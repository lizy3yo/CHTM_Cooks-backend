<?php

namespace App\Http\Controllers;

use App\Models\ClassCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Bulk imports for setting up a term: class codes, instructors and students.
 *
 * The client parses the spreadsheet and posts its rows. Every row is checked
 * before anything is written and the rows are saved in one transaction, so a
 * sheet with one bad row changes nothing and can simply be fixed and re-sent.
 * Problems are reported against the spreadsheet row they sit on.
 */
class DataImportController extends Controller
{
    private const MAX_ROWS = 500;

    /** Setting up accounts and classes is a superadmin task. */
    private function authorizeImport()
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'superadmin') {
            return response()->json(['error' => 'You do not have permission to import data.'], 403);
        }
        return null;
    }

    /** @return array{0: ?array, 1: mixed} rows, or an error response */
    private function rows(Request $request): array
    {
        $rows = $request->input('rows');
        if (!is_array($rows) || count($rows) === 0) {
            return [null, response()->json(['error' => 'The file has no rows to import.'], 400)];
        }
        if (count($rows) > self::MAX_ROWS) {
            return [null, response()->json(['error' => 'Import up to ' . self::MAX_ROWS . ' rows at a time.'], 400)];
        }
        return [$rows, null];
    }

    private function rejected(array $errors)
    {
        return response()->json([
            'error' => 'Some rows need fixing. Nothing was imported.',
            'rowErrors' => array_slice($errors, 0, 100),
        ], 422);
    }

    /** The active class a course code and section refer to, newest first. */
    private function findClass(string $courseCode, string $section): ?ClassCode
    {
        return ClassCode::whereRaw('LOWER(course_code) = ?', [mb_strtolower(trim($courseCode))])
            ->whereRaw('LOWER(section) = ?', [mb_strtolower(trim($section))])
            ->where('is_archived', false)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * POST /api/users/import
     *
     * Create student or instructor accounts. A student row may name a class
     * (course code and section) to be enrolled into.
     */
    public function users(Request $request)
    {
        if ($denied = $this->authorizeImport()) {
            return $denied;
        }

        $header = Validator::make($request->all(), [
            'role' => 'required|in:student,instructor',
            'temporaryPassword' => 'required|string|min:8|max:72',
        ]);
        if ($header->fails()) {
            return response()->json(['error' => $header->errors()->first()], 400);
        }

        [$rows, $error] = $this->rows($request);
        if ($error) {
            return $error;
        }

        $role = $request->input('role');
        $isStudent = $role === 'student';
        $errors = [];
        $seen = [];
        $classes = [];

        $emails = collect($rows)->map(fn ($r) => mb_strtolower(trim((string) ($r['email'] ?? ''))))->filter()->all();
        $taken = User::whereIn('email', $emails)->pluck('email')->map(fn ($e) => mb_strtolower($e))->flip();

        foreach ($rows as $index => $row) {
            $line = $index + 2; // Row 1 of the sheet is the header.
            $row = is_array($row) ? $row : [];

            $validator = Validator::make($row, [
                'firstName' => 'required|string|max:100',
                'lastName' => 'required|string|max:100',
                'email' => 'required|email|max:255',
                'yearLevel' => ($isStudent ? 'required' : 'nullable') . '|string|max:50',
                'block' => ($isStudent ? 'required' : 'nullable') . '|string|max:50',
                'courseCode' => 'nullable|string|max:100|required_with:section',
                'section' => 'nullable|string|max:50|required_with:courseCode',
            ], [], [
                'firstName' => 'first name', 'lastName' => 'last name',
                'yearLevel' => 'year level', 'courseCode' => 'course code',
            ]);

            foreach ($validator->errors()->all() as $message) {
                $errors[] = ['row' => $line, 'message' => $message];
            }

            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            if ($email !== '') {
                if (isset($taken[$email])) {
                    $errors[] = ['row' => $line, 'message' => "{$email} already has an account."];
                } elseif (isset($seen[$email])) {
                    $errors[] = ['row' => $line, 'message' => "{$email} is repeated from row {$seen[$email]}."];
                }
                $seen[$email] ??= $line;
            }

            if ($isStudent && !empty($row['courseCode']) && !empty($row['section'])) {
                $class = $this->findClass($row['courseCode'], $row['section']);
                if (!$class) {
                    $errors[] = ['row' => $line, 'message' => "No class found for {$row['courseCode']} section {$row['section']}. Import the class codes first."];
                } else {
                    $classes[$index] = $class;
                }
            }
        }

        if ($errors) {
            return $this->rejected($errors);
        }

        // Hash once: every account in the batch starts on the same temporary password.
        $password = Hash::make($request->input('temporaryPassword'));

        $summary = DB::transaction(function () use ($rows, $role, $isStudent, $password, $classes) {
            $enrolled = 0;

            foreach ($rows as $index => $row) {
                $user = User::create([
                    'email' => mb_strtolower(trim($row['email'])),
                    'password' => $password,
                    'role' => $role,
                    'first_name' => trim($row['firstName']),
                    'last_name' => trim($row['lastName']),
                    'is_active' => true,
                    'email_verified' => true, // Admin-created accounts are pre-verified
                    'year_level' => $isStudent ? trim((string) $row['yearLevel']) : null,
                    'block' => $isStudent ? trim((string) $row['block']) : null,
                    'trust_score' => 100,
                ]);

                if (isset($classes[$index])) {
                    $classes[$index]->students()->syncWithoutDetaching([$user->id]);
                    $enrolled++;
                }
            }

            return ['imported' => count($rows), 'enrolled' => $enrolled];
        });

        return response()->json(array_merge(['success' => true], $summary));
    }

    /**
     * POST /api/class-codes/import
     *
     * Create classes, or update the ones that already exist for the same
     * course, section, academic year and semester, and assign the instructor.
     */
    public function classCodes(Request $request)
    {
        if ($denied = $this->authorizeImport()) {
            return $denied;
        }

        [$rows, $error] = $this->rows($request);
        if ($error) {
            return $error;
        }

        $errors = [];
        $instructors = [];
        $semesters = ['first' => 'First', '1st' => 'First', '1' => 'First', 'second' => 'Second', '2nd' => 'Second', '2' => 'Second', 'summer' => 'Summer'];

        $known = User::where('role', 'instructor')
            ->whereIn('email', collect($rows)->map(fn ($r) => mb_strtolower(trim((string) ($r['instructorEmail'] ?? ''))))->filter()->all())
            ->get()
            ->keyBy(fn ($u) => mb_strtolower($u->email));

        foreach ($rows as $index => &$row) {
            $line = $index + 2;
            $row = is_array($row) ? $row : [];

            // Accept "1st", "first semester" and the like.
            $semesterKey = mb_strtolower(trim(str_ireplace('semester', '', (string) ($row['semester'] ?? ''))));
            $row['semester'] = $semesters[$semesterKey] ?? ($row['semester'] ?? null);

            $validator = Validator::make($row, [
                'courseCode' => 'required|string|max:100',
                'courseName' => 'required|string|max:255',
                'section' => 'required|string|max:50',
                'academicYear' => 'required|string|max:50',
                'semester' => 'required|in:First,Second,Summer',
                'maxEnrollment' => 'nullable|integer|min:1|max:1000',
                'instructorEmail' => 'nullable|email|max:255',
            ], [
                'semester.in' => 'Semester must be First, Second or Summer.',
            ], [
                'courseCode' => 'course code', 'courseName' => 'course name',
                'academicYear' => 'academic year', 'maxEnrollment' => 'max enrollment',
                'instructorEmail' => 'instructor email',
            ]);

            foreach ($validator->errors()->all() as $message) {
                $errors[] = ['row' => $line, 'message' => $message];
            }

            $email = mb_strtolower(trim((string) ($row['instructorEmail'] ?? '')));
            if ($email !== '') {
                if (!isset($known[$email])) {
                    $errors[] = ['row' => $line, 'message' => "No instructor account for {$email}. Import the instructors first."];
                } else {
                    $instructors[$index] = $known[$email];
                }
            }
        }
        unset($row);

        if ($errors) {
            return $this->rejected($errors);
        }

        $summary = DB::transaction(function () use ($rows, $instructors) {
            $created = 0;
            $updated = 0;
            $assigned = 0;

            foreach ($rows as $index => $row) {
                $class = ClassCode::whereRaw('LOWER(course_code) = ?', [mb_strtolower(trim($row['courseCode']))])
                    ->whereRaw('LOWER(section) = ?', [mb_strtolower(trim($row['section']))])
                    ->where('academic_year', trim($row['academicYear']))
                    ->where('semester', $row['semester'])
                    ->first();

                if ($class) {
                    $class->update([
                        'course_name' => trim($row['courseName']),
                        'max_enrollment' => $row['maxEnrollment'] ?? $class->max_enrollment,
                    ]);
                    $updated++;
                } else {
                    do {
                        $code = strtoupper(Str::random(6));
                    } while (ClassCode::where('code', $code)->exists());

                    $class = ClassCode::create([
                        'code' => $code,
                        'course_code' => trim($row['courseCode']),
                        'course_name' => trim($row['courseName']),
                        'section' => trim($row['section']),
                        'academic_year' => trim($row['academicYear']),
                        'semester' => $row['semester'],
                        'max_enrollment' => $row['maxEnrollment'] ?? 40,
                        'is_active' => true,
                        'is_archived' => false,
                    ]);
                    $created++;
                }

                if (isset($instructors[$index])) {
                    $class->instructors()->syncWithoutDetaching([$instructors[$index]->id]);
                    $assigned++;
                }
            }

            return ['created' => $created, 'updated' => $updated, 'instructorsAssigned' => $assigned];
        });

        return response()->json(array_merge(['success' => true], $summary));
    }
}

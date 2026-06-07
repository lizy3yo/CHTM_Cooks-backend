<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClassCode;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use DB;

class ClassCodeController extends Controller
{
    /**
     * @param \App\Models\ClassCode $class
     * @param bool $populate
     * @return array
     */
    private function transformClassCode($class, $populate = false)
    {
        $instructors = $class->instructors;
        $students = $class->students;

        $res = [
            'id' => (string) $class->id,
            'code' => $class->code,
            'courseCode' => $class->course_code,
            'courseName' => $class->course_name,
            'section' => $class->section,
            'academicYear' => $class->academic_year,
            'semester' => $class->semester,
            'maxEnrollment' => (int) $class->max_enrollment,
            'studentCount' => $students->count(),
            'instructorCount' => $instructors->count(),
            'isActive' => (bool) $class->is_active,
            'isArchived' => (bool) $class->is_archived,
            'createdAt' => $class->created_at->toIso8601String(),
            'updatedAt' => $class->updated_at->toIso8601String(),
        ];

        if ($populate) {
            $res['instructors'] = $instructors->map(fn($u) => [
                'id' => (string) $u->id,
                'firstName' => $u->first_name,
                'lastName' => $u->last_name,
                'email' => $u->email,
                'profilePhotoUrl' => $u->profile_photo_url
            ]);

            $res['students'] = $students->map(fn($u) => [
                'id' => (string) $u->id,
                'firstName' => $u->first_name,
                'lastName' => $u->last_name,
                'email' => $u->email,
                'yearLevel' => $u->year_level,
                'block' => $u->block,
                'profilePhotoUrl' => $u->profile_photo_url
            ]);
        } else {
            $res['instructorIds'] = $instructors->pluck('id')->map(fn($id) => (string) $id)->toArray();
            $res['studentIds'] = $students->pluck('id')->map(fn($id) => (string) $id)->toArray();
        }

        return $res;
    }

    public function getAll(Request $request)
    {
        $query = ClassCode::query();

        if ($request->filled('archived')) {
            $query->where('is_archived', $request->boolean('archived'));
        }

        if ($request->filled('semester')) {
            $query->where('semester', $request->semester);
        }

        if ($request->filled('academicYear')) {
            $query->where('academic_year', $request->academicYear);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', $search)
                  ->orWhere('course_code', 'like', $search)
                  ->orWhere('course_name', 'like', $search)
                  ->orWhere('section', 'like', $search);
            });
        }

        $total = $query->count();
        $limit = $request->integer('limit', 50);
        $page = $request->integer('page', 1);
        $totalPages = max(1, ceil($total / $limit));

        $classes = $query->orderBy('created_at', 'desc')
                         ->skip(($page - 1) * $limit)
                         ->take($limit)
                         ->get();

        return response()->json([
            'classCodes' => $classes->map(fn(ClassCode $c) => $this->transformClassCode($c)),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $totalPages
            ]
        ]);
    }

    /**
     * GET /api/class-codes/my-classes
     *
     * Return only the class codes relevant to the authenticated user:
     *   - Students  → classes they are enrolled in
     *   - Instructors → classes they are assigned to teach
     *   - Superadmin / Custodian → all active, non-archived classes
     */
    public function getMyClasses(Request $request)
    {
        $user = auth()->user();

        if ($user->role === 'student') {
            $query = ClassCode::whereHas('students', function ($q) use ($user) {
                $q->where('users.id', $user->id);
            });
        } elseif ($user->role === 'instructor') {
            $query = ClassCode::whereHas('instructors', function ($q) use ($user) {
                $q->where('users.id', $user->id);
            });
        } else {
            $query = ClassCode::where('is_active', true)->where('is_archived', false);
        }

        $populate = $request->boolean('populate', false);

        $classes = $query
            ->orderBy('academic_year', 'desc')
            ->orderBy('semester')
            ->orderBy('course_code')
            ->get();

        return response()->json([
            'classCodes' => $classes->map(fn(ClassCode $c) => $this->transformClassCode($c, $populate))
        ]);
    }

    public function getById(Request $request, $id)
    {
        $class = ClassCode::find($id);
        if (!$class) {
            return response()->json(['error' => 'Class code not found'], 404);
        }

        $populate = $request->boolean('populate', false);

        return response()->json([
            'classCode' => $this->transformClassCode($class, $populate)
        ]);
    }

    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'courseCode' => 'required|string|max:100',
            'courseName' => 'required|string|max:255',
            'section' => 'required|string|max:50',
            'academicYear' => 'required|string|max:50',
            'semester' => 'required|in:First,Second,Summer',
            'maxEnrollment' => 'required|integer|min:1',
            'instructorIds' => 'nullable|array',
            'instructorIds.*' => 'integer|exists:users,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        // Generate unique class code
        $code = strtoupper(Str::random(6));
        while (ClassCode::where('code', $code)->exists()) {
            $code = strtoupper(Str::random(6));
        }

        $class = ClassCode::create([
            'code' => $code,
            'course_code' => $request->courseCode,
            'course_name' => $request->courseName,
            'section' => $request->section,
            'academic_year' => $request->academicYear,
            'semester' => $request->semester,
            'max_enrollment' => $request->maxEnrollment,
            'is_active' => true,
            'is_archived' => false
        ]);

        if ($request->filled('instructorIds')) {
            $class->instructors()->sync($request->instructorIds);
        }

        return response()->json([
            'classCode' => $this->transformClassCode($class)
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $class = ClassCode::find($id);
        if (!$class) {
            return response()->json(['error' => 'Class code not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'courseName' => 'sometimes|required|string|max:255',
            'maxEnrollment' => 'sometimes|required|integer|min:1',
            'isActive' => 'sometimes|boolean',
            'isArchived' => 'sometimes|boolean',
            'instructorIds' => 'nullable|array',
            'instructorIds.*' => 'integer|exists:users,id',
            'semester' => 'sometimes|required|in:First,Second,Summer',
            'academicYear' => 'sometimes|required|string|max:50'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $updateData = [];
        if ($request->has('courseName')) $updateData['course_name'] = $request->courseName;
        if ($request->has('maxEnrollment')) $updateData['max_enrollment'] = $request->maxEnrollment;
        if ($request->has('isActive')) $updateData['is_active'] = $request->isActive;
        if ($request->has('isArchived')) $updateData['is_archived'] = $request->isArchived;
        if ($request->has('semester')) $updateData['semester'] = $request->semester;
        if ($request->has('academicYear')) $updateData['academic_year'] = $request->academicYear;

        $class->update($updateData);

        if ($request->has('instructorIds')) {
            $class->instructors()->sync($request->instructorIds);
        }

        return response()->json([
            'classCode' => $this->transformClassCode($class)
        ]);
    }

    public function delete($id)
    {
        $class = ClassCode::find($id);
        if (!$class) {
            return response()->json(['error' => 'Class code not found'], 404);
        }

        $class->delete();

        return response()->json([
            'success' => true,
            'message' => 'Class code deleted successfully'
        ]);
    }

    public function enrollStudents(Request $request, $id)
    {
        $class = ClassCode::find($id);
        if (!$class) {
            return response()->json(['error' => 'Class code not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'studentIds' => 'required|array',
            'studentIds.*' => 'integer|exists:users,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        // Attach unique student IDs
        $class->students()->syncWithoutDetaching($request->studentIds);

        return response()->json([
            'studentCount' => $class->students()->count()
        ]);
    }

    public function unenrollStudents(Request $request, $id)
    {
        $class = ClassCode::find($id);
        if (!$class) {
            return response()->json(['error' => 'Class code not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'studentIds' => 'required|array',
            'studentIds.*' => 'integer|exists:users,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $class->students()->detach($request->studentIds);

        return response()->json([
            'studentCount' => $class->students()->count()
        ]);
    }

    public function getStats()
    {
        $totalClasses = ClassCode::count();
        $activeClasses = ClassCode::where('is_active', true)->where('is_archived', false)->count();
        $archivedClasses = ClassCode::where('is_archived', true)->count();
        $totalStudents = DB::table('class_code_student')->distinct('user_id')->count();
        $totalInstructors = DB::table('class_code_instructor')->distinct('user_id')->count();
        
        $avgClassSize = 0;
        if ($totalClasses > 0) {
            $enrollmentsCount = DB::table('class_code_student')->count();
            $avgClassSize = round($enrollmentsCount / $totalClasses, 1);
        }

        return response()->json([
            'stats' => [
                'totalClasses' => $totalClasses,
                'activeClasses' => $activeClasses,
                'archivedClasses' => $archivedClasses,
                'totalStudents' => $totalStudents,
                'avgClassSize' => $avgClassSize,
                'totalInstructors' => $totalInstructors
            ]
        ]);
    }

    public function stream()
    {
        return new StreamedResponse(function () {
            echo "event: connected\n";
            echo "data: {}\n\n";
            ob_flush();
            flush();

            // Simple keep alive comments
            $start = time();
            while (time() - $start < 30) {
                echo "event: heartbeat\n";
                echo "data: {}\n\n";
                ob_flush();
                flush();
                sleep(10);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}

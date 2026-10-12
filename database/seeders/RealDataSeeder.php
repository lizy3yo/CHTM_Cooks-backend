<?php

namespace Database\Seeders;

use App\Models\BorrowRequest;
use App\Models\BorrowRequestEvent;
use App\Models\BorrowRequestItem;
use App\Models\ClassCode;
use App\Models\Course;
use App\Models\Donation;
use App\Models\DonationDeed;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\ReplacementObligation;
use App\Models\User;
use App\Models\WalkInTransaction;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Loads the college's real records from database/seed-data:
 *
 *   students.json   the registrar's enrollment list (one account per student)
 *   courses.json    the laboratory courses highlighted in the prospectus
 *   donations.json  the responses to the Deed of Donation form
 *   inventory.json  the laboratory's inventory export
 *
 * and replaces the fictional demo data the old StudentClassSeeder created.
 * It also adds sample activity for the reports: completed requests from the
 * past ten weeks, and two requests in progress at each stage.
 *
 * Run with:  php artisan db:seed --class=RealDataSeeder
 * Hosted:    set RUN_SEEDERS=true and SEED_REAL_DATA=true, then redeploy
 *            (DatabaseSeeder calls this class when SEED_REAL_DATA is true).
 *
 * Safe to rerun. Accounts are matched by email and only their roster fields
 * are refreshed, so a password a student has already changed is kept. Classes,
 * deeds, donations and walk-ins are keyed by fixed codes and references.
 * Nothing here sends email.
 *
 * Every student signs in as <student number>@gordoncollege.edu.ph with the
 * starting password (STUDENT_DEFAULT_PASSWORD) and must change it first.
 */
class RealDataSeeder extends Seeder
{
    private const EMAIL_DOMAIN = '@gordoncollege.edu.ph';
    private const FALLBACK_PASSWORD = 'Password@123';
    private const BLOCK_SIZE = 40;
    private const CLASS_CAPACITY = 45;
    private const HISTORY_PER_CLASS = 3;
    private const LIVE_PER_STAGE = 2;

    /** Only these students are placed in classes, so only they can borrow. */
    private const ENROLLED = 'Officially Enrolled';
    /** Kept on record but unable to sign in. */
    private const INACTIVE_STATUSES = ['Dropped', 'Will Not Enroll'];

    private const YEAR_LABELS = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];

    /** Only used when the inventory has fewer than 5 borrowable items. */
    private array $fallbackItems = [
        ['Chef\'s Knife 8"',        'Tools',     20, 2],
        ['Paring Knife',            'Tools',     20, 2],
        ['Cutting Board (Plastic)', 'Tools',     25, 2],
        ['Balloon Whisk',           'Tools',     20, 2],
        ['Measuring Spoons Set',    'Tools',     15, 1],
        ['Saucepan 2L',             'Equipment', 12, 1],
        ['Stock Pot 8L',            'Equipment', 8,  1],
        ['Sheet Pan',               'Equipment', 20, 2],
        ['Kitchen Tongs',           'Tools',     20, 2],
        ['Rubber Spatula',          'Tools',     25, 2],
        ['Ladle',                   'Tools',     20, 2],
    ];

    public function run(): void
    {
        $instructor = User::where('role', 'instructor')->orderBy('id')->first();
        $admin      = User::whereIn('role', ['superadmin', 'admin'])->orderBy('id')->first();

        if (!$instructor || !$admin) {
            $this->command->error('Run the base DatabaseSeeder first (needs an instructor and an admin).');
            return;
        }

        $roster    = $this->read('students.json');
        $catalog   = $this->read('courses.json');
        $deedsFile = $this->read('donations.json');
        $stockFile = $this->read('inventory.json');

        $removed  = $this->removeFictionalData($roster['students']);
        $students = $this->seedStudents($roster['students']);
        $classes  = $this->seedCoursesAndClasses($catalog['courses'], $roster['academicYear'], $instructor);
        $enrolled = $this->enrollStudents($classes);
        $stock    = $this->seedInventory($stockFile['items'], $admin);
        $deeds    = $this->seedDeeds($deedsFile['deeds'], $admin);
        $this->tidyCategories();

        $inventory = $this->ensureInventory($admin);
        $custodian = User::where('role', 'custodian')->orderBy('id')->first() ?? $admin;
        $cleared   = $this->removeEarlierSampleRequests();
        $history   = $this->seedHistory($classes, $instructor, $custodian, $admin, $inventory);
        $live      = $this->seedLiveRequests($classes, $instructor, $custodian, $admin, $inventory);
        $walkIns   = $this->seedWalkIns($classes, $admin, $inventory);

        $this->command->info(sprintf(
            'Students: %d new, %d refreshed (%d cannot sign in). Classes: %d, with %d enrollments.',
            $students['created'], $students['updated'], $students['inactive'], count($classes), $enrolled
        ));
        $this->command->info(sprintf(
            'Inventory: %d new items, %d already there (category and details refreshed, counts kept).',
            $stock['created'], $stock['updated']
        ));
        $this->command->info(sprintf(
            'Deeds: %d (%d new item lines, %d new receipt-only). Walk-ins: %d new.',
            $deeds['deeds'], $deeds['lines'], $deeds['receiptOnly'], $walkIns
        ));
        $this->command->info(sprintf(
            'Requests: %d completed in the past added, %d under review, %d with the custodian, %d released (%d earlier samples removed).',
            $history, $live['pending_instructor'], $live['approved_instructor'], $live['borrowed'], $cleared
        ));
        $this->command->info(sprintf(
            'Removed fictional data: %d students, %d classes, %d donations, %d walk-ins.',
            $removed['students'], $removed['classes'], $removed['donations'], $removed['walkIns']
        ));
    }

    private function read(string $file): array
    {
        $path = database_path('seed-data/' . $file);
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Seed data file is missing or not valid JSON: {$path}");
        }
        return $data;
    }

    // ── Fictional demo data from the old StudentClassSeeder ─────────────────

    private function removeFictionalData(array $rosterStudents): array
    {
        $real = array_flip(array_column($rosterStudents, 'studentNumber'));

        // The old seeder numbered its 40 students <year><class 01-04><001-010>.
        $fakeEmails = [];
        foreach ([2026, 2025, 2024, 2023] as $classIndex => $year) {
            for ($i = 1; $i <= 10; $i++) {
                $number = sprintf('%d%02d%03d', $year, $classIndex + 1, $i);
                if (!isset($real[$number])) {
                    $fakeEmails[] = $number . self::EMAIL_DOMAIN;
                }
            }
        }

        $fakeDonors = [
            'Gordon College Alumni Association', 'Chef Marites Dizon', 'Olongapo Restaurant Owners Guild',
            'Bautista Family', 'Subic Bay Hotel and Suites',
        ];

        return DB::transaction(function () use ($fakeEmails, $fakeDonors) {
            $donations = 0;
            foreach (Donation::whereIn('donor_name', $fakeDonors)->where('receipt_number', 'like', 'DON-%-90__')->get() as $donation) {
                // The old seeder raised both counters, so both come back down.
                $item = $donation->inventory_item_id ? InventoryItem::find($donation->inventory_item_id) : null;
                if ($item) {
                    $item->donations = max(0, $item->donations - $donation->quantity);
                    $item->eom_count = max(0, $item->eom_count - $donation->quantity);
                    $item->save();
                }
                $donation->delete();
                $donations++;
            }

            $walkIns = WalkInTransaction::whereIn('reference', ['W-SEED01', 'W-SEED02', 'W-SEED03', 'W-SEED04', 'W-SEED05'])->get();
            $walkInCount = $walkIns->count();
            $walkIns->each->delete();

            // Deleting a class or a student also deletes their borrow requests.
            $classes  = ClassCode::whereIn('code', ['CUL1A1', 'CUL2A1', 'CUL3A1', 'CUL4A1'])->delete();
            $students = User::where('role', 'student')->whereIn('email', $fakeEmails)->delete();

            return ['students' => $students, 'classes' => $classes, 'donations' => $donations, 'walkIns' => $walkInCount];
        });
    }

    // ── Students ────────────────────────────────────────────────────────────

    private function seedStudents(array $rows): array
    {
        $blocks = $this->assignBlocks($rows);
        $passwordHash = Hash::make(env('STUDENT_DEFAULT_PASSWORD') ?: self::FALLBACK_PASSWORD);
        $now = Carbon::now();

        $existing = User::whereNotNull('email')->pluck('id', 'email');
        $created = 0;
        $updated = 0;
        $inactive = 0;
        $inserts = [];

        foreach ($rows as $row) {
            $email  = $row['studentNumber'] . self::EMAIL_DOMAIN;
            $active = !in_array($row['enrollmentStatus'], self::INACTIVE_STATUSES, true);
            $inactive += $active ? 0 : 1;

            $fields = [
                'first_name'        => $this->properName($row['firstName']),
                'last_name'         => $this->properName($row['lastName']),
                'middle_name'       => $row['middleName'] !== '' ? $this->properName($row['middleName']) : null,
                'name_suffix'       => $row['suffix'] !== '' ? $row['suffix'] : null,
                'student_number'    => $row['studentNumber'],
                'program'           => $row['program'],
                'enrollment_status' => $row['enrollmentStatus'],
                'year_level'        => self::YEAR_LABELS[$row['yearLevel']] ?? (string) $row['yearLevel'],
                'block'             => $blocks[$row['studentNumber']],
                'is_active'         => $active,
                'updated_at'        => $now,
            ];

            if (isset($existing[$email])) {
                DB::table('users')->where('id', $existing[$email])->update($fields);
                $updated++;
                continue;
            }

            $inserts[] = $fields + [
                'email'                => $email,
                'password'             => $passwordHash,
                'role'                 => 'student',
                'email_verified'       => true,
                'agreed_to_terms'      => true,
                'trust_score'          => 100,
                'must_change_password' => true,
                'created_at'           => $now,
            ];
            $created++;
        }

        foreach (array_chunk($inserts, 200) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        // The base demo student is not on the roster; give it the fields the
        // roster students have so it behaves like one of them.
        User::where('email', '202311564' . self::EMAIL_DOMAIN)->whereNull('student_number')->update([
            'student_number' => '202311564', 'program' => 'BSHM', 'enrollment_status' => self::ENROLLED,
        ]);

        return ['created' => $created, 'updated' => $updated, 'inactive' => $inactive];
    }

    /**
     * Blocks A, B, C... per year level, filled alphabetically by surname,
     * about BLOCK_SIZE students each. Returns [studentNumber => block].
     */
    private function assignBlocks(array $rows): array
    {
        $byYear = [];
        foreach ($rows as $row) {
            $byYear[$row['yearLevel']][] = $row;
        }

        $blocks = [];
        foreach ($byYear as $students) {
            usort($students, fn($a, $b) => [$a['lastName'], $a['firstName'], $a['studentNumber']] <=> [$b['lastName'], $b['firstName'], $b['studentNumber']]);
            $blockCount = (int) ceil(count($students) / self::BLOCK_SIZE);
            $perBlock   = (int) ceil(count($students) / $blockCount);
            foreach ($students as $i => $student) {
                $blocks[$student['studentNumber']] = chr(65 + intdiv($i, $perBlock));
            }
        }
        return $blocks;
    }

    /** The registrar's list is in capitals: "DELA CRUZ" becomes "Dela Cruz". */
    private function properName(string $name): string
    {
        return mb_convert_case(mb_strtolower(trim($name)), MB_CASE_TITLE, 'UTF-8');
    }

    // ── Courses and classes ─────────────────────────────────────────────────

    /**
     * One laboratory class per course per block of its year level.
     * Returns [['class' => ClassCode, 'course' => array, 'block' => 'A'], ...].
     */
    private function seedCoursesAndClasses(array $courses, string $academicYear, User $instructor): array
    {
        $blocksByYear = User::where('role', 'student')->whereNotNull('student_number')->whereNotNull('block')
            ->select('year_level', 'block')->distinct()->get()
            ->groupBy('year_level')
            ->map(fn($rows) => $rows->pluck('block')->sort()->values()->all());

        $classes = [];
        foreach ($courses as $spec) {
            $course = Course::updateOrCreate(
                ['code' => $spec['code']],
                [
                    'title'      => $spec['title'],
                    'program'    => 'BSHM',
                    'year_level' => $spec['yearLevel'],
                    'semester'   => $spec['semester'],
                    'lab_units'  => $spec['labUnits'],
                    'is_active'  => true,
                ]
            );

            $yearLabel = self::YEAR_LABELS[$spec['yearLevel']];
            foreach ($blocksByYear[$yearLabel] ?? [] as $block) {
                $section = $spec['yearLevel'] . $block; // "1A"
                $class = ClassCode::updateOrCreate(
                    ['code' => str_replace(' ', '', $spec['code']) . '-' . $section], // "HPC101-1A"
                    [
                        'course_id'      => $course->id,
                        'course_code'    => $spec['code'],
                        'course_name'    => $spec['title'] . ' (Lab)',
                        'section'        => $section,
                        'academic_year'  => $academicYear,
                        'semester'       => $spec['semester'],
                        'max_enrollment' => self::CLASS_CAPACITY,
                        'is_active'      => true,
                        'is_archived'    => false,
                    ]
                );
                $class->instructors()->syncWithoutDetaching([$instructor->id]);
                $classes[] = ['class' => $class, 'course' => $spec, 'block' => $block, 'yearLabel' => $yearLabel];
            }
        }
        return $classes;
    }

    private function enrollStudents(array $classes): int
    {
        $rows = [];
        foreach ($classes as $entry) {
            $studentIds = User::where('role', 'student')
                ->where('year_level', $entry['yearLabel'])
                ->where('block', $entry['block'])
                ->where('enrollment_status', self::ENROLLED)
                ->where('is_active', true)
                ->pluck('id');
            foreach ($studentIds as $id) {
                $rows[] = ['class_code_id' => $entry['class']->id, 'user_id' => $id];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('class_code_student')->insertOrIgnore($chunk);
        }
        return count($rows);
    }

    // ── Inventory ───────────────────────────────────────────────────────────

    private const ITEM_TYPES = ['Tools', 'Equipment'];

    /** An item's category is its type: Tools or Equipment. */
    private function categoryFor(string $type, User $admin): InventoryCategory
    {
        $name = in_array($type, self::ITEM_TYPES, true) ? $type : 'Tools';

        return InventoryCategory::firstOrCreate(
            ['name' => $name],
            ['description' => "Laboratory {$name}", 'created_by' => $admin->id]
        );
    }

    /**
     * The laboratory's items, matched by name and specification.
     *
     * An item already in the system keeps its counts (they are newer than the
     * export) and only has its category, type, picture and required mark
     * refreshed. A missing item is created with the exported count as both its
     * stock and its starting count, so it begins with no variance. The export's
     * EOM Count, Variance and Status are not carried over.
     */
    private function seedInventory(array $rows, User $admin): array
    {
        // Case and spacing are ignored, so "SPECIALPLATE  1" and "Specialplate 1" are one item.
        $norm = fn(?string $text) => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $text)));
        $key = fn(?string $name, ?string $spec) => $norm($name) . '|' . $norm($spec);

        $existing = [];
        foreach (InventoryItem::where('archived', false)->get() as $item) {
            $existing[$key($item->name, $item->specification)] ??= $item;
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, $admin, $key, $existing, &$created, &$updated) {
            foreach ($rows as $row) {
                $category = $this->categoryFor($row['type'], $admin);
                $item = $existing[$key($row['name'], $row['specification'])] ?? null;

                if ($item) {
                    // Query builder: this is bookkeeping, not an edit to the
                    // item, so updated_at stays put.
                    DB::table('inventory_items')->where('id', $item->id)->update([
                        'category'           => $category->name,
                        'category_id'        => $category->id,
                        'tools_or_equipment' => $category->name,
                        'picture'            => $item->picture ?: $row['picture'],
                        'is_required'        => $item->is_required || $row['required'],
                    ]);
                    $updated++;
                    continue;
                }

                InventoryItem::create([
                    'name'               => $row['name'],
                    'specification'      => $row['specification'] !== '' ? $row['specification'] : null,
                    'category'           => $category->name,
                    'category_id'        => $category->id,
                    'tools_or_equipment' => $category->name,
                    'picture'            => $row['picture'],
                    'quantity'           => $row['quantity'],
                    'donations'          => $row['donations'],
                    'eom_count'          => $row['quantity'] + $row['donations'],
                    'status'             => 'In Stock',
                    'is_required'        => $row['required'],
                    'created_by'         => $admin->id,
                ]);
                $created++;
            }
        });

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Every item typed Tools or Equipment sits in the category of that name;
     * the catch-all categories that held them before are removed once empty.
     */
    private function tidyCategories(): void
    {
        foreach (self::ITEM_TYPES as $type) {
            $category = InventoryCategory::where('name', $type)->first();
            if (!$category) {
                continue;
            }
            DB::table('inventory_items')
                ->where('tools_or_equipment', $type)
                ->where(fn($q) => $q->where('category', '!=', $type)->orWhereNull('category_id')->orWhere('category_id', '!=', $category->id))
                ->update(['category' => $type, 'category_id' => $category->id]);
        }

        foreach (['All Items', 'Stock Room', 'Donated Items', 'Kitchen Tools'] as $legacy) {
            $category = InventoryCategory::where('name', $legacy)->first();
            $inUse = $category && DB::table('inventory_items')
                ->where(fn($q) => $q->where('category_id', $category->id)->orWhere('category', $legacy))
                ->exists();
            if ($category && !$inUse) {
                $category->delete();
            }
        }

        foreach (InventoryCategory::all() as $category) {
            $category->update(['item_count' => $category->items()->count()]);
        }
    }

    // ── Deeds of donation ───────────────────────────────────────────────────

    /**
     * These donations were received in past months that are already closed,
     * so their units are part of each item's starting count (eom_count) and
     * the rows are dated when they were donated. They therefore do not show
     * as this month's Over in the variance report.
     */
    private function seedDeeds(array $deeds, User $admin): array
    {
        $count = ['deeds' => 0, 'lines' => 0, 'receiptOnly' => 0];

        DB::transaction(function () use ($deeds, $admin, &$count) {
            foreach ($deeds as $spec) {
                $donated   = Carbon::parse($spec['dateDonated'])->setTime(9, 0);
                $submitted = Carbon::parse($spec['submittedAt']);

                // A submitter who used their school account is the student.
                $studentNumber = str_ends_with($spec['submitterEmail'], self::EMAIL_DOMAIN)
                    ? substr($spec['submitterEmail'], 0, -strlen(self::EMAIL_DOMAIN)) : null;
                $studentId = $studentNumber && ctype_digit($studentNumber)
                    ? User::where('student_number', $studentNumber)->value('id') : null;

                $deed = DonationDeed::updateOrCreate(
                    ['reference' => $spec['reference']],
                    [
                        'representative_name' => $spec['representativeName'],
                        'submitter_email'     => $spec['submitterEmail'],
                        'student_id'          => $studentId,
                        'program'             => $spec['program'],
                        'date_donated'        => $donated->toDateString(),
                        'or_number'           => $spec['orNumber'] ?? null,
                        'declared_total'      => $spec['declaredTotal'] ?? null,
                        'notes'               => $spec['notes'] ?? null,
                        'files'               => $spec['files'],
                        'submitted_at'        => $submitted,
                        'encoded_by'          => $admin->id,
                    ]
                );
                $count['deeds']++;

                $lines = $spec['items'];
                if (!$lines) {
                    // Receipt only: an amount on record, nothing added to stock.
                    $lines = [['receiptOnly' => true, 'name' => 'Cash donation (OR No. ' . $spec['orNumber'] . ')', 'quantity' => 1, 'unitCost' => $spec['declaredTotal']]];
                }

                foreach ($lines as $i => $line) {
                    $receipt = sprintf('%s-%02d', $spec['reference'], $i + 1);
                    if (Donation::where('receipt_number', $receipt)->exists()) {
                        continue;
                    }

                    $item = null;
                    $action = 'new_item';
                    if (empty($line['receiptOnly'])) {
                        $item = InventoryItem::where('archived', false)
                            ->whereRaw('LOWER(name) = ?', [mb_strtolower($line['name'])])->first();
                        if ($item) {
                            $item->increment('donations', $line['quantity']);
                            $item->increment('eom_count', $line['quantity']);
                            $action = 'add_to_existing';
                        } else {
                            $category = $this->categoryFor($line['type'] ?? 'Tools', $admin);
                            $item = InventoryItem::create([
                                'name'               => $line['name'],
                                'category'           => $category->name,
                                'category_id'        => $category->id,
                                'tools_or_equipment' => $category->name,
                                'quantity'           => 0,
                                'donations'          => $line['quantity'],
                                'eom_count'          => $line['quantity'],
                                'unit_price'         => $line['unitCost'],
                                'status'             => 'In Stock',
                                'created_by'         => $admin->id,
                            ]);
                        }
                        $count['lines']++;
                    } else {
                        $count['receiptOnly']++;
                    }

                    $donation = new Donation([
                        'receipt_number'    => $receipt,
                        'donor_name'        => $spec['representativeName'],
                        'item_name'         => $item->name ?? $line['name'],
                        'quantity'          => $line['quantity'],
                        'unit'              => empty($line['receiptOnly']) ? 'pcs' : null,
                        'purpose'           => empty($line['receiptOnly']) ? 'Item donation to CHTM laboratory' : 'Cash donation to CHTM laboratory',
                        'date'              => $donated,
                        'notes'             => $spec['notes'] ?? null,
                        'inventory_action'  => $action,
                        'inventory_item_id' => $item?->id,
                        'created_by'        => $admin->id,
                        'unit_cost'         => $line['unitCost'],
                        'deed_status'       => 'signed',
                        'deed_file_url'     => $spec['files'][0] ?? null,
                        'deed_signed_at'    => $submitted,
                        'deed_uploaded_by'  => $admin->id,
                        'deed_id'           => $deed->id,
                    ]);
                    $donation->timestamps = false;
                    $donation->created_at = $donated;
                    $donation->updated_at = $submitted;
                    $donation->save();
                }
            }
        });

        return $count;
    }

    // ── Sample activity ─────────────────────────────────────────────────────

    /** Borrowable inventory. Creates a small culinary set only if the table is (nearly) empty. */
    private function ensureInventory(User $admin)
    {
        $borrowable = fn() => InventoryItem::where('archived', false)
            ->where('status', '!=', 'Archived')
            ->where('quantity', '>', 0)
            ->get();

        $items = $borrowable();
        if ($items->count() >= 5) {
            return $items;
        }

        foreach ($this->fallbackItems as [$name, $type, $qty, $maxPerRequest]) {
            $category = $this->categoryFor($type, $admin);
            InventoryItem::firstOrCreate(
                ['name' => $name],
                [
                    'category'                 => $category->name,
                    'category_id'              => $category->id,
                    'tools_or_equipment'       => $type,
                    'quantity'                 => $qty,
                    'eom_count'                => $qty,
                    'status'                   => 'In Stock',
                    'max_quantity_per_request' => $maxPerRequest,
                    'created_by'               => $admin->id,
                ]
            );
        }
        $this->tidyCategories();

        return $borrowable();
    }

    /**
     * Sample borrow requests are created by the admin on the student's behalf
     * (`created_by` is not the student), which is how a rerun tells them from
     * requests students made themselves.
     */
    private function seededRequests()
    {
        return BorrowRequest::whereColumn('created_by', '!=', 'student_id');
    }

    /**
     * The first version of this seeder opened one pending request per class,
     * written as if the student had made it. Those are removed: none of them
     * can be real, because their students have never signed in.
     */
    private function removeEarlierSampleRequests(): int
    {
        return BorrowRequest::where('status', 'pending_instructor')
            ->whereColumn('created_by', 'student_id')
            ->whereIn('student_id', User::where('role', 'student')->whereNotNull('student_number')->whereNull('last_login')->select('id'))
            ->delete();
    }

    /** Roster students of a class, in a fixed order. */
    private function classStudents(ClassCode $class)
    {
        return $class->students()->whereNotNull('student_number')->orderBy('last_name')->orderBy('first_name')->get();
    }

    /** A request with its item lines. Timestamps are set by the caller. */
    private function makeRequest(array $entry, User $student, User $instructor, User $admin, Carbon $borrowDate, array $fields, $inventory, int $seed): BorrowRequest
    {
        $purposes = $entry['course']['purposes'];

        $request = new BorrowRequest($fields + [
            'student_id'     => $student->id,
            'instructor_id'  => $instructor->id,
            'class_code_id'  => $entry['class']->id,
            'purpose'        => $purposes[$seed % count($purposes)],
            'usage_location' => 'school',
            'borrow_date'    => $borrowDate,
            'return_date'    => $borrowDate->copy()->addDay()->setTime(17, 0),
            'created_by'     => $admin->id,
        ]);
        // Dated when it happened, not when the seeder ran.
        $request->timestamps = false;
        $request->created_at = $fields['created_at'] ?? Carbon::now();
        $request->updated_at = $fields['updated_at'] ?? $request->created_at;
        $request->save();

        // Fixed choice per request, so a rerun on an empty database gives the same picture.
        $count = min($inventory->count(), 3 + $seed % 3);
        for ($i = 0; $i < $count; $i++) {
            $item = $inventory[($seed * 7 + $i * 13) % $inventory->count()];
            if ($request->items()->where('item_id', $item->id)->exists()) {
                continue;
            }
            BorrowRequestItem::create([
                'borrow_request_id' => $request->id,
                'item_id'           => $item->id,
                'name'              => $item->name,
                'quantity'          => max(1, min(1 + ($seed + $i) % 3, $item->max_quantity_per_request ?: 3, $item->quantity + $item->donations)),
                'category'          => $item->category,
                'picture'           => $item->picture,
            ]);
        }

        return $request;
    }

    private function event(BorrowRequest $request, string $event, ?User $actor, Carbon $at, ?array $metadata = null): void
    {
        if (!BorrowRequestEvent::available()) {
            return;
        }
        BorrowRequestEvent::create([
            'borrow_request_id' => $request->id,
            'event'             => $event,
            'actor_id'          => $actor?->id,
            'actor_name'        => $actor ? trim($actor->first_name . ' ' . $actor->last_name) : null,
            'actor_role'        => $actor?->role,
            'metadata'          => $metadata,
            'occurred_at'       => $at,
        ]);
    }

    /**
     * Completed requests from the past ten weeks, three per class, so the
     * reports and analytics have something to show. Each was borrowed and
     * returned, so stock is unchanged. Most came back on time and in good
     * condition; a few were late, and a few had one damaged unit that the
     * student has since replaced.
     */
    private function seedHistory(array $classes, User $instructor, User $custodian, User $admin, $inventory): int
    {
        if ($inventory->isEmpty()) {
            return 0;
        }

        $created = 0;
        $weekStart = Carbon::today()->startOfWeek();

        DB::transaction(function () use ($classes, $instructor, $custodian, $admin, $inventory, $weekStart, &$created) {
            foreach ($classes as $index => $entry) {
                $class = $entry['class'];
                $students = $this->classStudents($class);
                $have = $this->seededRequests()->where('class_code_id', $class->id)->whereIn('status', ['returned', 'resolved'])->count();

                for ($n = $have; $n < self::HISTORY_PER_CLASS && $students->isNotEmpty(); $n++) {
                    $seed = $index * self::HISTORY_PER_CLASS + $n;
                    $student = $students[($index + $n * 5) % $students->count()];

                    // A weekday, one to ten weeks ago.
                    $borrowed  = $weekStart->copy()->subWeeks(1 + $seed % 10)->addDays($seed % 5)->setTime(8, 0);
                    $due       = $borrowed->copy()->addDay()->setTime(17, 0);
                    $late      = $seed % 9 === 4;
                    $damaged   = $seed % 20 === 7;
                    $returned  = $late ? $due->copy()->addDay()->setTime(10, 0) : $due->copy()->subHours(1 + $seed % 3);
                    $submitted = $borrowed->copy()->subDays(2)->setTime(10, 0);
                    $approved  = $submitted->copy()->addHours(4);
                    $ready     = $borrowed->copy()->subDay()->setTime(15, 0);
                    $replaced  = $returned->copy()->addDays(3)->setTime(9, 0);

                    $request = $this->makeRequest($entry, $student, $instructor, $admin, $borrowed, [
                        'custodian_id' => $custodian->id,
                        'status'       => $damaged ? 'resolved' : 'returned',
                        'approved_at'  => $approved,
                        'released_at'  => $ready,
                        'picked_up_at' => $borrowed,
                        'returned_at'  => $returned,
                        'missing_at'   => $damaged ? $returned : null,
                        'resolved_at'  => $damaged ? $replaced : null,
                        'updated_by'   => $custodian->id,
                        'created_at'   => $submitted,
                        'updated_at'   => $damaged ? $replaced : $returned,
                    ], $inventory, $seed);

                    foreach ($request->items as $line => $item) {
                        $bad = $damaged && $line === 0 ? 1 : 0;
                        $item->update([
                            'inspection_status'      => $bad ? 'damaged' : 'good',
                            'inspection_date'        => $returned,
                            'inspected_by'           => $custodian->id,
                            'inspection_notes'       => $bad ? 'Chipped during the laboratory session.' : null,
                            'replacement_quantity'   => $bad ?: null,
                            'due_date'               => $bad ? $returned->copy()->addDays(7) : null,
                            'good_quantity'          => $item->quantity - $bad,
                            'damaged_quantity'       => $bad,
                            'missing_quantity'       => 0,
                            'over_returned_quantity' => 0,
                        ]);

                        if ($bad) {
                            $obligation = new ReplacementObligation([
                                'borrow_request_id' => $request->id,
                                'student_id'        => $student->id,
                                'item_id'           => $item->item_id,
                                'item_name'         => $item->name,
                                'item_category'     => $item->category,
                                'quantity'          => 1,
                                'type'              => 'damaged',
                                'status'            => 'replaced',
                                'amount'            => 1,
                                'amount_paid'       => 1,
                                'resolution_type'   => 'replacement',
                                'resolution_date'   => $replaced,
                                'resolution_notes'  => 'Replaced with a new unit of the same kind.',
                                'incident_date'     => $returned,
                                'incident_notes'    => 'Chipped during the laboratory session.',
                                'due_date'          => $returned->copy()->addDays(7),
                                'created_by'        => $custodian->id,
                                'updated_by'        => $custodian->id,
                            ]);
                            $obligation->timestamps = false;
                            $obligation->created_at = $returned;
                            $obligation->updated_at = $replaced;
                            $obligation->save();
                        }
                    }

                    $this->event($request, 'submitted', $student, $submitted);
                    $this->event($request, 'approved', $instructor, $approved);
                    $this->event($request, 'ready_for_pickup', $custodian, $ready);
                    $this->event($request, 'picked_up', $custodian, $borrowed);
                    $this->event($request, 'inspected', $custodian, $returned, ['outcome' => $damaged ? 'item_issue' : 'returned']);
                    if ($damaged) {
                        $this->event($request, 'resolved', $custodian, $replaced);
                    }
                    $created++;
                }
            }
        });

        return $created;
    }

    /**
     * Requests in progress, two at each stage: waiting for the instructor,
     * approved and waiting for the custodian, and released to the student.
     * A rerun only tops a stage back up to two.
     */
    private function seedLiveRequests(array $classes, User $instructor, User $custodian, User $admin, $inventory): array
    {
        $made = ['pending_instructor' => 0, 'approved_instructor' => 0, 'borrowed' => 0];
        if ($inventory->isEmpty() || !$classes) {
            return $made;
        }

        // Statuses that count as "still at this stage".
        $stages = [
            'pending_instructor'  => ['pending_instructor'],
            'approved_instructor' => ['approved_instructor', 'ready_for_pickup'],
            'borrowed'            => BorrowRequest::OUT_STATUSES,
        ];
        $active = ['pending_instructor', 'approved_instructor', 'ready_for_pickup', 'borrowed', 'pending_return', 'pending_appeal', 'missing'];

        $now  = Carbon::now();
        $slot = 0;

        DB::transaction(function () use ($classes, $instructor, $custodian, $admin, $inventory, $stages, $active, $now, &$slot, &$made) {
            foreach ($stages as $status => $counted) {
                $have = $this->seededRequests()->whereIn('status', $counted)->count();

                for ($n = $have; $n < self::LIVE_PER_STAGE; $n++) {
                    // A different class each time, and a student with nothing else in progress.
                    $student = null;
                    for ($try = 0; $try < count($classes) && !$student; $try++) {
                        $entry = $classes[($slot * 9 + $try) % count($classes)];
                        $student = $this->classStudents($entry['class'])
                            ->first(fn($s) => !BorrowRequest::where('student_id', $s->id)->whereIn('status', $active)->exists());
                    }
                    $slot++;
                    if (!$student) {
                        continue;
                    }

                    $seed = 1000 + $slot;
                    $submitted = $now->copy()->subDay()->setTime(10 + $slot % 5, 15);

                    if ($status === 'borrowed') {
                        $borrowDate = Carbon::today()->setTime(8, 0);
                        $fields = [
                            'custodian_id' => $custodian->id,
                            'approved_at'  => $submitted->copy()->addHours(2),
                            'released_at'  => $submitted->copy()->addHours(4),
                            'picked_up_at' => $borrowDate,
                            'updated_by'   => $custodian->id,
                            'updated_at'   => $borrowDate,
                        ];
                    } else {
                        $borrowDate = Carbon::tomorrow()->addDays($n % 2)->setTime(8, 0);
                        $fields = $status === 'approved_instructor'
                            ? ['approved_at' => $submitted->copy()->addHours(2), 'updated_by' => $instructor->id, 'updated_at' => $submitted->copy()->addHours(2)]
                            : [];
                    }

                    $request = $this->makeRequest($entry, $student, $instructor, $admin, $borrowDate, $fields + [
                        'status' => $status, 'created_at' => $submitted,
                    ], $inventory, $seed);

                    $this->event($request, 'submitted', $student, $submitted);
                    if ($status !== 'pending_instructor') {
                        $this->event($request, 'approved', $instructor, $fields['approved_at']);
                    }
                    if ($status === 'borrowed') {
                        $this->event($request, 'ready_for_pickup', $custodian, $fields['released_at']);
                        $this->event($request, 'picked_up', $custodian, $borrowDate);

                        // The units are with the student, so they leave the shelf
                        // exactly as they do at pickup: stock first, then donations.
                        foreach ($request->items as $line) {
                            $item = InventoryItem::find($line->item_id);
                            $fromStock = min($line->quantity, max(0, $item->quantity));
                            $item->quantity -= $fromStock;
                            $item->donations = max(0, $item->donations - ($line->quantity - $fromStock));
                            $item->save();
                        }
                    }
                    $made[$status]++;
                }
            }
        });

        return $made;
    }

    /** Five walk-ins by enrolled students: three still borrowed, two returned. */
    private function seedWalkIns(array $classes, User $admin, $inventory): int
    {
        if ($inventory->isEmpty() || !$classes) {
            return 0;
        }

        $plans = [
            // [reference, purpose, status, borrow offset (days), return offset]
            ['W-DEMO01', 'Makeup laboratory session', 'borrowed',  0,  1],
            ['W-DEMO02', 'Extra practice before the practical exam', 'borrowed',  0,  2],
            ['W-DEMO03', 'Plating rehearsal', 'returned', -2, -1],
            ['W-DEMO04', 'Group activity preparation', 'borrowed',  0,  1],
            ['W-DEMO05', 'Student organization event', 'returned', -3, -2],
        ];

        $now = Carbon::now();
        $count = 0;
        $step = max(1, intdiv(count($classes), count($plans)));

        foreach ($plans as $i => [$reference, $purpose, $status, $borrowOffset, $returnOffset]) {
            if (WalkInTransaction::where('reference', $reference)->exists()) {
                continue;
            }

            $class   = $classes[($i * $step) % count($classes)]['class'];
            $student = $class->students()->whereNotNull('student_number')->orderBy('last_name', 'desc')->first();
            if (!$student) {
                continue;
            }

            $return = $now->copy()->addDays($returnOffset)->setTime(16, 0);
            $walkIn = WalkInTransaction::create([
                'reference'          => $reference,
                'student_id'         => $student->id,
                'student_name'       => trim($student->first_name . ' ' . $student->last_name),
                'student_identifier' => (string) $student->id,
                'email'              => $student->email,
                'class_code'         => $class->code,
                'purpose'            => $purpose,
                'usage_location'     => 'school',
                'borrow_date'        => $now->copy()->addDays($borrowOffset)->setTime(9, 0),
                'return_date'        => $return,
                'status'             => $status,
                'returned_at'        => $status === 'returned' ? $return : null,
                'created_by'         => $admin->id,
            ]);

            foreach ($inventory->shuffle()->take(min($inventory->count(), rand(2, 3))) as $item) {
                $walkIn->items()->create([
                    'item_id'           => $item->id,
                    'name'              => $item->name,
                    'category'          => $item->category,
                    'quantity'          => $this->quantityFor($item),
                    'inspection_status' => $status === 'returned' ? 'good' : null,
                ]);
            }
            $count++;
        }
        return $count;
    }

    /** 1 to 3 units, never above the item's per-request cap or its stock. */
    private function quantityFor(InventoryItem $item): int
    {
        $cap = $item->max_quantity_per_request ?: 3;
        return max(1, min(rand(1, 3), $cap, $item->quantity + $item->donations));
    }
}

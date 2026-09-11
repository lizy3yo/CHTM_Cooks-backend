<?php

namespace Database\Seeders;

use App\Models\BorrowRequest;
use App\Models\BorrowRequestItem;
use App\Models\ClassCode;
use App\Models\Donation;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\User;
use App\Models\WalkInTransaction;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data: 4 classes x 10 students, one pending borrow request per student,
 * 5 donations and 5 walk-in transactions.
 *
 * Run with:  php artisan db:seed --class=StudentClassSeeder
 * On Render: set RUN_SEEDERS=true and SEED_DEMO_DATA=true, then redeploy
 *            (DatabaseSeeder calls this class when SEED_DEMO_DATA is true).
 *
 * Safe to rerun: students/classes are upserted, requests are only created for
 * students who do not have one yet, donations and walk-ins are keyed by fixed
 * receipt/reference numbers, pivot rows are synced without detaching.
 * The existing seeded student (202311564@gordoncollege.edu.ph) is left alone.
 */
class StudentClassSeeder extends Seeder
{
    private const PASSWORD = 'Password@123';
    private const EMAIL_DOMAIN = '@gordoncollege.edu.ph';
    private const MIN_ITEMS_PER_REQUEST = 5;
    private const MAX_ITEMS_PER_REQUEST = 7;

    /**
     * One entry per class. 'year' prefixes the student number (2026xxxxx = 1st Year).
     */
    private array $classes = [
        [
            'code' => 'CUL1A1', 'course_code' => 'CUL 101',
            'course_name' => 'Fundamentals of Culinary Arts',
            'section' => '1A', 'year_level' => '1st Year', 'year' => 2026,
            'purposes' => ['Knife skills practical', 'Basic stocks and sauces lab', 'Mise en place drill'],
        ],
        [
            'code' => 'CUL2A1', 'course_code' => 'CUL 201',
            'course_name' => 'Culinary Techniques and Kitchen Operations',
            'section' => '2A', 'year_level' => '2nd Year', 'year' => 2025,
            'purposes' => ['Dry heat cooking methods lab', 'Moist heat cooking methods lab', 'Kitchen station rotation'],
        ],
        [
            'code' => 'CUL3A1', 'course_code' => 'CUL 301',
            'course_name' => 'International Cuisine',
            'section' => '3A', 'year_level' => '3rd Year', 'year' => 2024,
            'purposes' => ['Italian cuisine practical', 'Asian cuisine practical', 'French classical dishes lab'],
        ],
        [
            'code' => 'CUL4A1', 'course_code' => 'CUL 401',
            'course_name' => 'Catering and Banquet Management',
            'section' => '4A', 'year_level' => '4th Year', 'year' => 2023,
            'purposes' => ['Banquet plating demo', 'Buffet setup practicum', 'Catering event mock service'],
        ],
    ];

    /** 40 fictional students, 10 per class, in class order. */
    private array $students = [
        // CUL 101 - 1st Year
        ['Andrea', 'Villanueva'], ['Joshua', 'Dela Cruz'], ['Patricia', 'Santos'], ['Miguel', 'Reyes'], ['Kyla', 'Bautista'],
        ['Rafael', 'Mendoza'], ['Bianca', 'Garcia'], ['Carlo', 'Torres'], ['Nicole', 'Ramos'], ['Jerome', 'Castillo'],
        // CUL 201 - 2nd Year
        ['Angelica', 'Fernandez'], ['Paolo', 'Aquino'], ['Danica', 'Flores'], ['Kevin', 'Gonzales'], ['Trisha', 'Navarro'],
        ['Mark', 'Salazar'], ['Jasmine', 'Domingo'], ['Adrian', 'Pascual'], ['Camille', 'Marquez'], ['Ryan', 'Lim'],
        // CUL 301 - 3rd Year
        ['Sophia', 'Cruz'], ['Daniel', 'Manalo'], ['Erika', 'Rivera'], ['Justin', 'Alvarez'], ['Mika', 'Ocampo'],
        ['Francis', 'Del Rosario'], ['Alyssa', 'Soriano'], ['Nathan', 'Tolentino'], ['Hannah', 'Padilla'], ['Bryan', 'Espino'],
        // CUL 401 - 4th Year
        ['Lorraine', 'Villamor'], ['Christian', 'Abad'], ['Denise', 'Cabrera'], ['Jayson', 'Ignacio'], ['Rochelle', 'Lazaro'],
        ['Vincent', 'Magbanua'], ['Shaira', 'Quijano'], ['Aldrin', 'Sarmiento'], ['Katrina', 'Yap'], ['Emmanuel', 'Zamora'],
    ];

    /** Only used when the inventory table has fewer than MIN_ITEMS_PER_REQUEST borrowable items. */
    private array $fallbackItems = [
        ['Chef\'s Knife 8"',        'Tools',     20, 2],
        ['Paring Knife',            'Tools',     20, 2],
        ['Cutting Board (Plastic)', 'Tools',     25, 2],
        ['Stainless Mixing Bowl',   'Tools',     30, 3],
        ['Balloon Whisk',           'Tools',     20, 2],
        ['Measuring Cups Set',      'Tools',     15, 1],
        ['Measuring Spoons Set',    'Tools',     15, 1],
        ['Saucepan 2L',             'Equipment', 12, 1],
        ['Frying Pan 10"',          'Equipment', 12, 1],
        ['Stock Pot 8L',            'Equipment', 8,  1],
        ['Sheet Pan',               'Equipment', 20, 2],
        ['Kitchen Tongs',           'Tools',     20, 2],
        ['Rubber Spatula',          'Tools',     25, 2],
        ['Ladle',                   'Tools',     20, 2],
        ['Digital Kitchen Scale',   'Equipment', 10, 1],
    ];

    /** [donor, quantity, unit, purpose, notes] — each attaches to a different inventory item. */
    private array $donations = [
        ['Gordon College Alumni Association', 5, 'pcs',  'Support for culinary laboratory classes', 'Brand new, boxed'],
        ['Chef Marites Dizon',                3, 'sets', 'Donation from a former faculty member',   'Lightly used, good condition'],
        ['Olongapo Restaurant Owners Guild', 10, 'pcs',  'Community partnership program',          null],
        ['Bautista Family',                   2, 'pcs',  'In memory of Chef Ramon Bautista',       'Engraved, for display and use'],
        ['Subic Bay Hotel and Suites',        6, 'pcs',  'Hospitality industry partner donation',  'From hotel kitchen surplus'],
    ];

    public function run(): void
    {
        $instructor = User::where('role', 'instructor')->orderBy('id')->first();
        $admin      = User::whereIn('role', ['superadmin', 'admin'])->orderBy('id')->first();

        if (!$instructor || !$admin) {
            $this->command->error('Run the base DatabaseSeeder first (needs an instructor and an admin).');
            return;
        }

        $inventory = $this->ensureInventory($admin);

        // First 5 students per class borrow tomorrow, the last 5 the day after.
        $tomorrow = Carbon::tomorrow();
        $dayAfter = Carbon::tomorrow()->addDay();

        $created = ['students' => 0, 'requests' => 0];

        DB::transaction(function () use ($instructor, $inventory, $tomorrow, $dayAfter, &$created) {
            foreach ($this->classes as $ci => $spec) {
                $class = ClassCode::updateOrCreate(
                    ['code' => $spec['code']],
                    [
                        'course_code'    => $spec['course_code'],
                        'course_name'    => $spec['course_name'],
                        'section'        => $spec['section'],
                        'academic_year'  => '2026-2027',
                        'semester'       => 'First',
                        'max_enrollment' => 10,
                        'is_active'      => true,
                        'is_archived'    => false,
                    ]
                );
                $class->instructors()->syncWithoutDetaching([$instructor->id]);

                $block = substr($spec['section'], -1); // 'A'

                for ($i = 0; $i < 10; $i++) {
                    [$first, $last] = $this->students[$ci * 10 + $i];

                    // e.g. 202601001 .. 202601010, 202502001 .. 202502010
                    $studentNo = sprintf('%d%02d%03d', $spec['year'], $ci + 1, $i + 1);
                    $email     = $studentNo . self::EMAIL_DOMAIN;

                    $student = User::updateOrCreate(
                        ['email' => $email],
                        [
                            'password'        => Hash::make(self::PASSWORD),
                            'role'            => 'student',
                            'first_name'      => $first,
                            'last_name'       => $last,
                            'is_active'       => true,
                            'email_verified'  => true,
                            'year_level'      => $spec['year_level'],
                            'block'           => $block,
                            'agreed_to_terms' => true,
                            'trust_score'     => 100,
                        ]
                    );
                    $created['students']++;

                    $class->students()->syncWithoutDetaching([$student->id]);

                    if (BorrowRequest::where('student_id', $student->id)->exists()) {
                        continue; // rerun: request already seeded
                    }

                    $borrowDate = ($i < 5 ? $tomorrow : $dayAfter)->copy()->setTime(8, 0);
                    $returnDate = $borrowDate->copy()->addDay()->setTime(17, 0);

                    $request = BorrowRequest::create([
                        'student_id'     => $student->id,
                        'instructor_id'  => $instructor->id,
                        'class_code_id'  => $class->id,
                        'purpose'        => $spec['purposes'][$i % count($spec['purposes'])],
                        'usage_location' => 'school',
                        'borrow_date'    => $borrowDate,
                        'return_date'    => $returnDate,
                        'status'         => 'pending_instructor',
                        'created_by'     => $student->id,
                    ]);

                    foreach ($this->pickItems($inventory) as $item) {
                        BorrowRequestItem::create([
                            'borrow_request_id' => $request->id,
                            'item_id'           => $item->id,
                            'name'              => $item->name,
                            'quantity'          => $this->quantityFor($item),
                            'category'          => $item->category,
                            'picture'           => $item->picture,
                        ]);
                    }
                    $created['requests']++;
                }
            }
        });

        $donations = $this->seedDonations($admin, $inventory);
        $walkIns   = $this->seedWalkIns($admin, $inventory);

        $this->command->info(sprintf(
            'Seeded 4 classes, %d students, %d new borrow requests (%d on %s, %d on %s), %d donations, %d walk-ins.',
            $created['students'],
            $created['requests'],
            (int) ceil($created['requests'] / 2), $tomorrow->toDateString(),
            (int) floor($created['requests'] / 2), $dayAfter->toDateString(),
            $donations,
            $walkIns
        ));
    }

    /**
     * Returns borrowable inventory. Creates a small culinary set only if the table is (nearly) empty.
     */
    private function ensureInventory(User $admin)
    {
        $items = InventoryItem::where('archived', false)
            ->where('status', '!=', 'Archived')
            ->where('quantity', '>', 0)
            ->get();

        if ($items->count() >= self::MIN_ITEMS_PER_REQUEST) {
            return $items;
        }

        $category = InventoryCategory::firstOrCreate(
            ['name' => 'Kitchen Tools'],
            ['description' => 'Seeded culinary tools and equipment', 'created_by' => $admin->id]
        );

        foreach ($this->fallbackItems as [$name, $type, $qty, $maxPerReq]) {
            InventoryItem::firstOrCreate(
                ['name' => $name],
                [
                    'category'                 => $category->name,
                    'category_id'              => $category->id,
                    'tools_or_equipment'       => $type,
                    'quantity'                 => $qty,
                    'status'                   => 'In Stock',
                    'max_quantity_per_request' => $maxPerReq,
                    'created_by'               => $admin->id,
                ]
            );
        }

        $category->update(['item_count' => $category->items()->count()]);

        return InventoryItem::where('archived', false)->where('quantity', '>', 0)->get();
    }

    /** 5 to 7 distinct items per request. */
    private function pickItems($inventory)
    {
        $count = min($inventory->count(), rand(self::MIN_ITEMS_PER_REQUEST, self::MAX_ITEMS_PER_REQUEST));
        return $inventory->shuffle()->take($count);
    }

    /** 1 to 3 units, never above the item's per-request cap or its stock. */
    private function quantityFor(InventoryItem $item): int
    {
        $cap = $item->max_quantity_per_request ?: 3;
        return max(1, min(rand(1, 3), $cap, $item->quantity + $item->donations));
    }

    /**
     * 5 donations, each "add to existing" on a different inventory item.
     * Mirrors the stock side effect of the app's donation form.
     */
    private function seedDonations(User $admin, $inventory): int
    {
        $today = Carbon::today();
        $items = $inventory->take(5)->values();
        $count = 0;

        foreach ($this->donations as $i => [$donor, $qty, $unit, $purpose, $notes]) {
            $receipt = sprintf('DON-%s-90%02d', $today->format('Ymd'), $i + 1);
            if (Donation::where('receipt_number', $receipt)->exists() || !isset($items[$i])) {
                continue;
            }

            $item = $items[$i];
            $item->increment('donations', $qty);
            $item->increment('eom_count', $qty);

            Donation::create([
                'receipt_number'    => $receipt,
                'donor_name'        => $donor,
                'item_name'         => $item->name,
                'quantity'          => $qty,
                'unit'              => $unit,
                'purpose'           => $purpose,
                'date'              => $today->copy()->subDays(4 - $i)->setTime(10, 0),
                'notes'             => $notes,
                'inventory_action'  => 'add_to_existing',
                'inventory_item_id' => $item->id,
                'created_by'        => $admin->id,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * 5 walk-in transactions: 3 registered seeded students, 2 guests.
     * 3 still borrowed, 2 returned with items inspected as good.
     */
    private function seedWalkIns(User $admin, $inventory): int
    {
        $now   = Carbon::now();
        $count = 0;

        $s1 = User::where('email', '202601001' . self::EMAIL_DOMAIN)->first();
        $s2 = User::where('email', '202502003' . self::EMAIL_DOMAIN)->first();
        $s3 = User::where('email', '202403005' . self::EMAIL_DOMAIN)->first();

        $walkIns = [
            // [reference, student|null, guest name, guest id, guest email, class code, purpose, status, borrow offset, return offset]
            ['W-SEED01', $s1,   null,              null,         null,                        'CUL1A1', 'Knife skills makeup session',   'borrowed',  0,  1],
            ['W-SEED02', $s2,   null,              null,         null,                        'CUL2A1', 'Extra practice for sauces lab', 'borrowed',  0,  2],
            ['W-SEED03', $s3,   null,              null,         null,                        'CUL3A1', 'Plating rehearsal',             'returned', -2, -1],
            ['W-SEED04', null,  'Marco Villareal', 'GUEST-0917', 'marco.villareal@gmail.com', null,     'Faculty demo preparation',      'borrowed',  0,  1],
            ['W-SEED05', null,  'Liza Manalang',   'GUEST-0918', null,                        null,     'Culinary club event',           'returned', -3, -2],
        ];

        foreach ($walkIns as [$ref, $student, $name, $ident, $email, $classCode, $purpose, $status, $bOff, $rOff]) {
            if (WalkInTransaction::where('reference', $ref)->exists()) {
                continue;
            }

            $borrow = $now->copy()->addDays($bOff)->setTime(9, 0);
            $return = $now->copy()->addDays($rOff)->setTime(16, 0);

            $walkIn = WalkInTransaction::create([
                'reference'          => $ref,
                'student_id'         => $student?->id,
                'student_name'       => $student ? "{$student->first_name} {$student->last_name}" : $name,
                'student_identifier' => $student ? (string) $student->id : $ident,
                'email'              => $student?->email ?? $email,
                'class_code'         => $classCode,
                'purpose'            => $purpose,
                'usage_location'     => 'school',
                'borrow_date'        => $borrow,
                'return_date'        => $return,
                'status'             => $status,
                'returned_at'        => $status === 'returned' ? $return : null,
                'notes'              => $student ? null : 'Guest borrower, ID checked at counter',
                'created_by'         => $admin->id,
            ]);

            foreach ($inventory->shuffle()->take(rand(2, 3)) as $item) {
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
}

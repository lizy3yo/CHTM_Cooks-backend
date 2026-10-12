<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three additions that let the system hold the college's real records:
 *
 *  - users: the registrar's roster fields (student number, middle name,
 *    suffix, program, enrollment status) and a flag that forces a password
 *    change on first sign-in for accounts created with a starting password.
 *  - courses: the laboratory courses classes are opened for, taken from the
 *    prospectus. `class_codes.course_id` links a class to its course.
 *  - donation_deeds: one deed of donation with its representative, program,
 *    official receipt and signed files. `donations.deed_id` groups the item
 *    lines given under the same deed.
 *
 * Plain strings instead of enums, so the same migration runs on PostgreSQL
 * (hosted), MySQL (local) and SQLite (tests). Guarded so a failed run can be
 * retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'student_number')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('student_number', 20)->nullable()->unique();
                $table->string('middle_name', 100)->nullable();
                $table->string('name_suffix', 10)->nullable();
                $table->string('program', 20)->nullable();
                $table->string('enrollment_status', 30)->nullable();
                $table->boolean('must_change_password')->default(false);
            });
        }

        if (!Schema::hasTable('courses')) {
            Schema::create('courses', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();
                $table->string('title');
                $table->string('program', 20)->default('BSHM');
                $table->unsignedTinyInteger('year_level');
                $table->string('semester', 10);
                $table->unsignedTinyInteger('lab_units')->default(1);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('class_codes', 'course_id')) {
            Schema::table('class_codes', function (Blueprint $table) {
                $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('donation_deeds')) {
            Schema::create('donation_deeds', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 30)->unique();
                $table->string('representative_name');
                $table->string('submitter_email')->nullable();
                $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('program', 20)->nullable();
                $table->date('date_donated');
                $table->string('or_number', 50)->nullable();
                // The total written on the deed. Kept beside the item prices
                // because the two do not always agree.
                $table->decimal('declared_total', 12, 2)->nullable();
                $table->text('notes')->nullable();
                // JSON array of URLs to the signed deed (PDF or image).
                $table->text('files')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('encoded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('donations', 'deed_id')) {
            Schema::table('donations', function (Blueprint $table) {
                $table->foreignId('deed_id')->nullable()->constrained('donation_deeds')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donations', 'deed_id')) {
            Schema::table('donations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('deed_id');
            });
        }
        Schema::dropIfExists('donation_deeds');

        if (Schema::hasColumn('class_codes', 'course_id')) {
            Schema::table('class_codes', function (Blueprint $table) {
                $table->dropConstrainedForeignId('course_id');
            });
        }
        Schema::dropIfExists('courses');

        if (Schema::hasColumn('users', 'student_number')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['student_number']);
                $table->dropColumn([
                    'student_number', 'middle_name', 'name_suffix',
                    'program', 'enrollment_status', 'must_change_password',
                ]);
            });
        }
    }
};

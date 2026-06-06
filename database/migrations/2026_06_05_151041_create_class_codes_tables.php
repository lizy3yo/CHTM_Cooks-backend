<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('class_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('course_code');
            $table->string('course_name');
            $table->string('section');
            $table->string('academic_year');
            $table->enum('semester', ['First', 'Second', 'Summer']);
            $table->integer('max_enrollment');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('class_code_instructor', function (Blueprint $table) {
            $table->foreignId('class_code_id')->constrained('class_codes')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->primary(['class_code_id', 'user_id']);
        });

        Schema::create('class_code_student', function (Blueprint $table) {
            $table->foreignId('class_code_id')->constrained('class_codes')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->primary(['class_code_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_code_student');
        Schema::dropIfExists('class_code_instructor');
        Schema::dropIfExists('class_codes');
    }
};

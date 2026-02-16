<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ocr_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 50);
            $table->json('layout')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('version', 10)->default('1.0');
            $table->timestamps();
            
            $table->index('type');
            $table->index('is_active');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ocr_templates');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('smart_ocr_template_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('smart_ocr_templates')->onDelete('cascade');
            $table->string('key', 50);
            $table->string('label');
            $table->string('type', 30)->default('string');
            $table->text('pattern')->nullable();
            $table->json('position')->nullable();
            $table->json('validators')->nullable();
            $table->string('default_value')->nullable();
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
            
            $table->index(['template_id', 'key']);
            $table->index('order');
        });
    }

    public function down()
    {
        Schema::dropIfExists('smart_ocr_template_fields');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ocr_processed_documents', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');
            $table->string('document_type', 50)->nullable();
            $table->json('extracted_data');
            $table->foreignId('template_id')->nullable()->constrained('ocr_templates')->nullOnDelete();
            $table->decimal('confidence_score', 3, 2)->default(0);
            $table->decimal('processing_time', 8, 3)->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 20)->default('completed');
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->index('document_type');
            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ocr_processed_documents');
    }
};
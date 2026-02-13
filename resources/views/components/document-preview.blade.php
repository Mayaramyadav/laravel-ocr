<div class="laravel-ocr-document-preview" x-data="documentPreview(@js($document))">
    <div class="preview-container">
        <div class="document-viewer">
            @if($showImage)
                <img :src="documentUrl" alt="Document Preview" class="document-image">
            @else
                <iframe :src="documentUrl" class="document-iframe"></iframe>
            @endif
            
            @if($showOverlay)
                <div class="ocr-overlay">
                    <template x-for="field in fields" :key="field.key">
                        <div 
                            class="field-highlight"
                            :style="{
                                left: field.bounds.x + 'px',
                                top: field.bounds.y + 'px',
                                width: field.bounds.width + 'px',
                                height: field.bounds.height + 'px'
                            }"
                            @click="selectField(field)"
                            :class="{ 'selected': selectedField?.key === field.key }"
                        >
                            <span class="field-label" x-text="field.label"></span>
                        </div>
                    </template>
                </div>
            @endif
        </div>
        
        <div class="extracted-data-panel">
            <h3>Extracted Data</h3>
            
            <div class="field-list">
                <template x-for="field in fields" :key="field.key">
                    <div 
                        class="field-item"
                        :class="{ 'selected': selectedField?.key === field.key }"
                        @click="selectField(field)"
                    >
                        <label x-text="field.label"></label>
                        <input 
                            type="text" 
                            :value="field.value"
                            @input="updateField(field.key, $event.target.value)"
                            class="field-input"
                        >
                        <span class="confidence-badge" :class="getConfidenceClass(field.confidence)">
                            <span x-text="Math.round(field.confidence * 100) + '%'"></span>
                        </span>
                    </div>
                </template>
            </div>
            
            @if($showActions)
                <div class="actions">
                    <button @click="saveChanges" class="btn btn-primary">Save Changes</button>
                    <button @click="exportData" class="btn btn-secondary">Export Data</button>
                    <button @click="reprocess" class="btn btn-outline">Reprocess</button>
                </div>
            @endif
        </div>
    </div>
</div>

<style>
.laravel-ocr-document-preview {
    display: flex;
    height: 100%;
    gap: 1rem;
}

.preview-container {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 1rem;
    width: 100%;
}

.document-viewer {
    position: relative;
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: auto;
}

.document-image,
.document-iframe {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.ocr-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
}

.field-highlight {
    position: absolute;
    border: 2px solid #007bff;
    background: rgba(0, 123, 255, 0.1);
    cursor: pointer;
    pointer-events: auto;
    transition: all 0.2s;
}

.field-highlight:hover {
    background: rgba(0, 123, 255, 0.2);
}

.field-highlight.selected {
    border-color: #28a745;
    background: rgba(40, 167, 69, 0.2);
}

.field-label {
    position: absolute;
    top: -20px;
    left: 0;
    font-size: 12px;
    background: #007bff;
    color: white;
    padding: 2px 6px;
    border-radius: 2px;
    white-space: nowrap;
}

.extracted-data-panel {
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 1.5rem;
    overflow-y: auto;
}

.field-list {
    margin-top: 1rem;
}

.field-item {
    margin-bottom: 1rem;
    padding: 0.75rem;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.field-item:hover {
    border-color: #007bff;
}

.field-item.selected {
    border-color: #28a745;
    background: #f8f9fa;
}

.field-item label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: #333;
}

.field-input {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
}

.confidence-badge {
    display: inline-block;
    margin-top: 0.25rem;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.confidence-badge.high {
    background: #d4edda;
    color: #155724;
}

.confidence-badge.medium {
    background: #fff3cd;
    color: #856404;
}

.confidence-badge.low {
    background: #f8d7da;
    color: #721c24;
}

.actions {
    margin-top: 2rem;
    display: flex;
    gap: 0.5rem;
}

.btn {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
}

.btn-outline {
    background: white;
    color: #007bff;
    border: 1px solid #007bff;
}

.btn-outline:hover {
    background: #007bff;
    color: white;
}
</style>

<script>
function documentPreview(initialData) {
    return {
        documentUrl: initialData.url || '',
        fields: initialData.fields || [],
        selectedField: null,
        
        selectField(field) {
            this.selectedField = field;
        },
        
        updateField(key, value) {
            const field = this.fields.find(f => f.key === key);
            if (field) {
                field.value = value;
                field.modified = true;
            }
        },
        
        getConfidenceClass(confidence) {
            if (confidence >= 0.8) return 'high';
            if (confidence >= 0.6) return 'medium';
            return 'low';
        },
        
        async saveChanges() {
            const modifiedFields = this.fields.filter(f => f.modified);
            
            try {
                const response = await fetch('/laravel-ocr/save', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        document_id: initialData.documentId,
                        fields: modifiedFields
                    })
                });
                
                if (response.ok) {
                    alert('Changes saved successfully!');
                    this.fields.forEach(f => f.modified = false);
                }
            } catch (error) {
                console.error('Error saving changes:', error);
                alert('Failed to save changes');
            }
        },
        
        exportData() {
            const data = {};
            this.fields.forEach(field => {
                data[field.key] = field.value;
            });
            
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'extracted-data.json';
            a.click();
            URL.revokeObjectURL(url);
        },
        
        async reprocess() {
            if (!confirm('Are you sure you want to reprocess this document?')) {
                return;
            }
            
            window.location.href = `/laravel-ocr/reprocess/${initialData.documentId}`;
        }
    };
}
</script>
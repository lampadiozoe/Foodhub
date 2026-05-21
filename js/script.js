// FoodHub — global JS helpers

document.addEventListener('DOMContentLoaded', function () {
    // Broken-image fallback for upload paths
    document.querySelectorAll('img[src*="/uploads/"], img[src*="uploads/"]').forEach(img => {
        img.addEventListener('error', function () {
            this.style.backgroundColor = '#f0f2f5';
            this.alt = 'Image not available';
        });
    });
});

// Confirm delete helper used by admin forms
function confirmDelete() {
    return confirm('Are you sure you want to delete this item?');
}

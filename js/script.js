// FoodHub JavaScript

// Handle missing images with placeholder
document.addEventListener('DOMContentLoaded', function() {
    const images = document.querySelectorAll('img[src*="/uploads/"], img[src*="uploads/"]');
    images.forEach(img => {
        img.addEventListener('error', function() {
            // Fallback to a data URI placeholder or color
            this.style.backgroundColor = '#f0f0f0';
            this.style.display = 'flex';
            this.style.alignItems = 'center';
            this.style.justifyContent = 'center';
            this.style.color = '#999';
            this.style.fontSize = '12px';
            this.alt = 'Image not available';
        });
    });

    // Initialize favorite buttons
    initializeFavorites();
});

// Initialize favorite functionality
function initializeFavorites() {
    const favoriteBtns = document.querySelectorAll('.favorite-btn');

    favoriteBtns.forEach(btn => {
        const productId = btn.dataset.productId;
        const productName = btn.dataset.productName;

        // Check if already favorited
        const favorites = getFavorites();
        if (favorites.includes(productId)) {
            btn.classList.add('favorited');
        }

        // Add click event
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            toggleFavorite(productId, productName, this);
        });
    });
}

// Get favorites from localStorage
function getFavorites() {
    const favorites = localStorage.getItem('foodhub_favorites');
    return favorites ? JSON.parse(favorites) : [];
}

// Save favorites to localStorage
function saveFavorites(favorites) {
    localStorage.setItem('foodhub_favorites', JSON.stringify(favorites));
}

// Toggle favorite status
function toggleFavorite(productId, productName, btn) {
    const favorites = getFavorites();
    const isFavorited = favorites.includes(productId);

    if (isFavorited) {
        // Remove from favorites
        const index = favorites.indexOf(productId);
        favorites.splice(index, 1);
        btn.classList.remove('favorited');
        showToast(`${productName} removed from favorites`, 'info');
    } else {
        // Add to favorites
        favorites.push(productId);
        btn.classList.add('favorited');
        showToast(`${productName} added to favorites ❤️`, 'success');
    }

    saveFavorites(favorites);
}

// Show toast notification
function showToast(message, type = 'info') {
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed`;
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.parentElement.parentElement.remove()"></button>
        </div>
    `;

    document.body.appendChild(toast);

    // Auto remove after 3 seconds
    setTimeout(() => {
        if (toast.parentElement) {
            toast.remove();
        }
    }, 3000);
}

// Confirm delete
function confirmDelete() {
    return confirm('Are you sure you want to delete this item?');
}

// Store form data to prevent loss on validation error
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            sessionStorage.setItem('lastFormData', JSON.stringify(new FormData(this)));
        });
    });
});
<?php
/**
 * getProductImage($product)
 * Returns a web-accessible URL for a product image.
 * Works regardless of which subfolder the calling script lives in.
 *
 * Resolution order:
 *  1. Full URL stored in image column  → return as-is
 *  2. Filename found in /uploads/      → return UPLOADS_URL . filename
 *  3. Known dish name fallback         → Unsplash URL
 *  4. Generic placeholder SVG
 */
function getProductImage(array $product): string {
    $uploadsDir = realpath(__DIR__ . '/../uploads') . DIRECTORY_SEPARATOR;
    $filename   = trim($product['image'] ?? '');

    // 1. Already a full URL
    if (!empty($filename) && filter_var($filename, FILTER_VALIDATE_URL)) {
        return $filename;
    }

    // 2. File exists on disk
    $basename = basename($filename);
    if (!empty($basename) && file_exists($uploadsDir . $basename)) {
        return UPLOADS_URL . rawurlencode($basename);
    }

    // 3. Fallback by dish name
    static $fallback = [
        'Chicken Adobo'     => 'https://images.unsplash.com/photo-1598103442097-8b74394b95c6?auto=format&fit=crop&w=800&q=80',
        'Pork Sinigang'     => 'https://images.unsplash.com/photo-1576134026800-5d0c0dd7e157?auto=format&fit=crop&w=800&q=80',
        'Beef Tapa'         => 'https://images.unsplash.com/photo-1607629710671-1179e2b5b9e9?auto=format&fit=crop&w=800&q=80',
        'Pancit Canton'     => 'https://images.unsplash.com/photo-1621996346565-e3dbc353d2e5?auto=format&fit=crop&w=800&q=80',
        'Lumpiang Shanghai' => 'https://images.unsplash.com/photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=800&q=80',
        'Halo-Halo'         => 'https://images.unsplash.com/photo-1520202402948-6032c31def1a?auto=format&fit=crop&w=800&q=80',
        'Sorbetes'          => 'https://images.unsplash.com/photo-1570197788417-0e82375c9371?auto=format&fit=crop&w=800&q=80',
    ];

    return $fallback[$product['name']] ?? IMG_PLACEHOLDER;
}

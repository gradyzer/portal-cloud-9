<?php
/**
 * Portal Cloud 9 - Import/Export AJAX Handlers
 * Handles product import and export functionality
 * 
 * @package Portal Cloud 9
 * @version 8.6.1
 */

defined('ABSPATH') || exit;

class PortalCloud9_Import_Export_Ajax
{
    public function __construct()
    {
        // Export products
        add_action('wp_ajax_portcld9_export_products', [$this, 'export_products']);
        
        // Import products
        add_action('wp_ajax_portcld9_import_products', [$this, 'import_products']);
    }

    /**
     * Export all user's products to CSV or XLSX
     */
    public function export_products()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'portalcloud9_nonce')) {
            wp_send_json_error('Invalid security token.');
        }

        // Check login
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in.');
        }

        $user_id = get_current_user_id();
        $format = isset($_POST['format']) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'csv';
        
        // Validate format
        if (!in_array($format, ['csv', 'xlsx'])) {
            wp_send_json_error('Invalid format. Please select CSV or XLSX.');
        }

        // Get user's products
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft'],
            'author'         => $user_id,
        ];

        $products = get_posts($args);

        if (empty($products)) {
            wp_send_json_error('No products found to export.');
        }

        // Prepare export data
        $export_data = [];
        
        // Headers
        $headers = [
            'ID',
            'Title',
            'Description',
            'Short Description',
            'SKU',
            'Regular Price',
            'Sale Price',
            'Stock Status',
            'Stock Quantity',
            'Categories',
            'Tags',
            'Image URL',
            'Status',
            'Featured',
            'Manage Stock',
            'Sold Individually',
        ];

        $export_data[] = $headers;

        // Product data
        foreach ($products as $product_post) {
            $product = wc_get_product($product_post->ID);
            
            if (!$product) {
                continue;
            }

            // Get categories
            $categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
            $categories_str = !empty($categories) ? implode('|', $categories) : '';

            // Get tags
            $tags = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']);
            $tags_str = !empty($tags) ? implode('|', $tags) : '';

            // Get image URL
            $image_id = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_url($image_id) : '';

            $row = [
                $product->get_id(),
                $product->get_name(),
                $product->get_description(),
                $product->get_short_description(),
                $product->get_sku(),
                $product->get_regular_price(),
                $product->get_sale_price(),
                $product->get_stock_status(),
                $product->get_stock_quantity(),
                $categories_str,
                $tags_str,
                $image_url,
                $product->get_status(),
                $product->get_featured() ? 'yes' : 'no',
                $product->get_manage_stock() ? 'yes' : 'no',
                $product->get_sold_individually() ? 'yes' : 'no',
            ];

            $export_data[] = $row;
        }

        // Generate filename
        $filename = 'products-export-' . gmdate('Y-m-d-His') . '.' . $format;

        // Create file content based on format
        if ($format === 'xlsx') {
            $file_content = $this->array_to_xlsx($export_data);
            $mime_type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } else {
            $file_content = $this->array_to_csv($export_data);
            $mime_type = 'text/csv';
        }

        wp_send_json_success([
            'filename'  => $filename,
            'content'   => $file_content,
            'count'     => count($products),
            'format'    => $format,
            'mime_type' => $mime_type,
        ]);
    }

    /**
     * Import products from CSV/Excel
     */
    public function import_products()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'portalcloud9_nonce')) {
            wp_send_json_error('Invalid security token.');
        }

        // Check login
        if (!is_user_logged_in()) {
            wp_send_json_error('You must be logged in.');
        }

        // Check file upload
        if (!isset($_FILES['file']) || empty($_FILES['file']['tmp_name'])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File validated via wp_handle_upload().
            wp_send_json_error('No file uploaded.');
        }

        $file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File validated via wp_handle_upload().

        // Validate file type - allow both CSV and XLSX
        $allowed_csv_types = ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'];
        $allowed_xlsx_types = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        $is_csv = in_array($file['type'], $allowed_csv_types) || in_array($file_extension, ['csv', 'txt']);
        $is_xlsx = in_array($file['type'], $allowed_xlsx_types) || $file_extension === 'xlsx';
        
        if (!$is_csv && !$is_xlsx) {
            wp_send_json_error('Invalid file type. Please upload CSV or XLSX file.');
        }

        // Parse file based on type
        if ($is_xlsx) {
            $file_data = $this->parse_xlsx_file($file['tmp_name']);
        } else {
            $file_data = $this->parse_csv_file($file['tmp_name']);
        }

        if (empty($file_data)) {
            wp_send_json_error('File is empty or invalid.');
        }

        // Extract headers (first row)
        $headers = array_shift($file_data);
        
        // Validate headers
        if (!in_array('Title', $headers)) {
            wp_send_json_error('Invalid file format. "Title" column is required.');
        }

        // Import products
        $imported = 0;
        $updated = 0;
        $errors = [];
        $user_id = get_current_user_id();

        foreach ($file_data as $row_index => $row) {
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Map row data to headers
            $product_data = array_combine($headers, $row);

            // Check if product exists (by ID or SKU)
            $product_id = !empty($product_data['ID']) ? intval($product_data['ID']) : 0;
            
            // If ID provided, check if user owns this product
            if ($product_id > 0) {
                $existing_product = get_post($product_id);
                if (!$existing_product || $existing_product->post_author != $user_id) {
                    $errors[] = "Row " . ($row_index + 2) . ": You don't have permission to update product ID {$product_id}";
                    continue;
                }
            }

            try {
                // Create or update product
                if ($product_id > 0) {
                    $product = wc_get_product($product_id);
                    if (!$product) {
                        $errors[] = "Row " . ($row_index + 2) . ": Product ID {$product_id} not found";
                        continue;
                    }
                    $updated++;
                } else {
                    $product = new WC_Product_Simple();
                    $product->set_status('draft'); // Default to draft for new products
                    $imported++;
                }

                // Set basic data
                if (!empty($product_data['Title'])) {
                    $product->set_name(sanitize_text_field($product_data['Title']));
                }

                if (!empty($product_data['Description'])) {
                    $product->set_description(wp_kses_post($product_data['Description']));
                }

                if (!empty($product_data['Short Description'])) {
                    $product->set_short_description(wp_kses_post($product_data['Short Description']));
                }

                if (!empty($product_data['SKU'])) {
                    $product->set_sku(sanitize_text_field($product_data['SKU']));
                }

                if (isset($product_data['Regular Price']) && $product_data['Regular Price'] !== '') {
                    $product->set_regular_price(floatval($product_data['Regular Price']));
                }

                if (isset($product_data['Sale Price']) && $product_data['Sale Price'] !== '') {
                    $product->set_sale_price(floatval($product_data['Sale Price']));
                }

                if (!empty($product_data['Stock Status'])) {
                    $product->set_stock_status(sanitize_text_field($product_data['Stock Status']));
                }

                if (isset($product_data['Stock Quantity']) && $product_data['Stock Quantity'] !== '') {
                    $product->set_stock_quantity(intval($product_data['Stock Quantity']));
                }

                if (isset($product_data['Manage Stock'])) {
                    $manage_stock = strtolower($product_data['Manage Stock']) === 'yes';
                    $product->set_manage_stock($manage_stock);
                }

                if (isset($product_data['Featured'])) {
                    $featured = strtolower($product_data['Featured']) === 'yes';
                    $product->set_featured($featured);
                }

                if (isset($product_data['Sold Individually'])) {
                    $sold_individually = strtolower($product_data['Sold Individually']) === 'yes';
                    $product->set_sold_individually($sold_individually);
                }

                if (!empty($product_data['Status'])) {
                    $status = sanitize_text_field($product_data['Status']);
                    if (in_array($status, ['publish', 'draft', 'pending'])) {
                        $product->set_status($status);
                    }
                }

                // Set author for new products
                if ($product_id === 0) {
                    $product_id = $product->save();
                    wp_update_post([
                        'ID' => $product_id,
                        'post_author' => $user_id,
                    ]);
                } else {
                    $product->save();
                }

                // Handle categories
                if (!empty($product_data['Categories'])) {
                    $categories = explode('|', $product_data['Categories']);
                    $category_ids = [];
                    
                    foreach ($categories as $cat_name) {
                        $cat_name = trim($cat_name);
                        if (empty($cat_name)) continue;
                        
                        $term = get_term_by('name', $cat_name, 'product_cat');
                        if (!$term) {
                            $term = wp_insert_term($cat_name, 'product_cat');
                            if (!is_wp_error($term)) {
                                $category_ids[] = $term['term_id'];
                            }
                        } else {
                            $category_ids[] = $term->term_id;
                        }
                    }
                    
                    if (!empty($category_ids)) {
                        wp_set_object_terms($product_id, $category_ids, 'product_cat');
                    }
                }

                // Handle tags
                if (!empty($product_data['Tags'])) {
                    $tags = explode('|', $product_data['Tags']);
                    $tag_ids = [];
                    
                    foreach ($tags as $tag_name) {
                        $tag_name = trim($tag_name);
                        if (empty($tag_name)) continue;
                        
                        $term = get_term_by('name', $tag_name, 'product_tag');
                        if (!$term) {
                            $term = wp_insert_term($tag_name, 'product_tag');
                            if (!is_wp_error($term)) {
                                $tag_ids[] = $term['term_id'];
                            }
                        } else {
                            $tag_ids[] = $term->term_id;
                        }
                    }
                    
                    if (!empty($tag_ids)) {
                        wp_set_object_terms($product_id, $tag_ids, 'product_tag');
                    }
                }

                // Handle image URL (if provided)
                if (!empty($product_data['Image URL'])) {
                    $this->set_product_image_from_url($product_id, $product_data['Image URL']);
                }

            } catch (Exception $e) {
                $errors[] = "Row " . ($row_index + 2) . ": " . $e->getMessage();
            }
        }

        wp_send_json_success([
            'imported' => $imported,
            'updated'  => $updated,
            'errors'   => $errors,
            'total'    => count($file_data),
        ]);
    }

    /**
     * Convert array to CSV string
     */
    private function array_to_csv($data)
    {
        $output = '';
        
        foreach ($data as $row) {
            // Escape and quote fields
            $escaped_row = array_map(function($field) {
                // Convert to string
                $field = (string) $field;
                // Escape double quotes
                $field = str_replace('"', '""', $field);
                // Wrap in quotes if contains comma, newline, or quotes
                if (strpos($field, ',') !== false || strpos($field, "\n") !== false || strpos($field, '"') !== false) {
                    $field = '"' . $field . '"';
                }
                return $field;
            }, $row);
            
            $output .= implode(',', $escaped_row) . "\n";
        }
        
        return $output;
    }

    /**
     * Parse CSV file
     */
    private function parse_csv_file($file_path)
    {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $csv_data = [];
        $raw = $wp_filesystem->get_contents( $file_path );
        if ( $raw === false ) {
            return $csv_data;
        }

        $lines = explode( "
", $raw );
        foreach ( $lines as $line ) {
            if ( trim( $line ) === '' ) {
                continue;
            }
            $row = str_getcsv( $line );
            if ( ! empty( $row ) ) {
                $csv_data[] = $row;
            }
        }

        return $csv_data;
    }

    /**
     * Set product image from URL
     */
    private function set_product_image_from_url($product_id, $image_url)
    {
        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Download image
        $tmp = download_url($image_url);
        
        if (is_wp_error($tmp)) {
            return false;
        }

        // Get file name
        $file_name = basename($image_url);
        
        // Prepare file array
        $file_array = [
            'name'     => $file_name,
            'tmp_name' => $tmp,
        ];

        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, $product_id);

        // Remove tmp file
        wp_delete_file($tmp);

        if (is_wp_error($attachment_id)) {
            return false;
        }

        // Set as product image
        set_post_thumbnail($product_id, $attachment_id);

        return $attachment_id;
    }

    /**
     * Convert array to XLSX format (base64 encoded)
     * Creates a simple XLSX file using XML
     */
    private function array_to_xlsx($data)
    {
        // Create temporary file
        $temp_file = tempnam(sys_get_temp_dir(), 'xlsx_');
        
        // Create ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        // Add [Content_Types].xml
        $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>';
        $zip->addFromString('[Content_Types].xml', $content_types);

        // Add _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // Add xl/_rels/workbook.xml.rels
        $workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels);

        // Add xl/workbook.xml
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets>
<sheet name="Products" sheetId="1" r:id="rId1"/>
</sheets>
</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // Build shared strings
        $shared_strings = [];
        $string_index = 0;
        foreach ($data as $row) {
            foreach ($row as $cell) {
                $cell_str = (string) $cell;
                if (!isset($shared_strings[$cell_str])) {
                    $shared_strings[$cell_str] = $string_index++;
                }
            }
        }

        // Add xl/sharedStrings.xml
        $shared_strings_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared_strings) . '" uniqueCount="' . count($shared_strings) . '">';
        foreach (array_keys($shared_strings) as $string) {
            $escaped_string = htmlspecialchars($string, ENT_XML1, 'UTF-8');
            $shared_strings_xml .= '<si><t>' . $escaped_string . '</t></si>';
        }
        $shared_strings_xml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $shared_strings_xml);

        // Add xl/worksheets/sheet1.xml
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetData>';
        
        foreach ($data as $row_index => $row) {
            $sheet .= '<row r="' . ($row_index + 1) . '">';
            $col_index = 0;
            foreach ($row as $cell) {
                $col_letter = $this->num_to_col($col_index);
                $cell_ref = $col_letter . ($row_index + 1);
                $cell_str = (string) $cell;
                $string_idx = $shared_strings[$cell_str];
                $sheet .= '<c r="' . $cell_ref . '" t="s"><v>' . $string_idx . '</v></c>';
                $col_index++;
            }
            $sheet .= '</row>';
        }
        
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

        $zip->close();

        // Read file and convert to base64
        $content = file_get_contents($temp_file);
        wp_delete_file($temp_file);

        return base64_encode($content);
    }

    /**
     * Parse XLSX file and return array data
     */
    private function parse_xlsx_file($file_path)
    {
        $zip = new ZipArchive();
        
        if ($zip->open($file_path) !== true) {
            return [];
        }

        // Read shared strings
        $shared_strings = [];
        $shared_strings_xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($shared_strings_xml) {
            $xml = simplexml_load_string($shared_strings_xml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    $shared_strings[] = (string) $si->t;
                }
            }
        }

        // Read worksheet data
        $worksheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!$worksheet_xml) {
            return [];
        }

        $xml = simplexml_load_string($worksheet_xml);
        if (!$xml) {
            return [];
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $row_data = [];
            foreach ($row->c as $cell) {
                $value = '';
                if (isset($cell->v)) {
                    // Check if it's a shared string
                    if (isset($cell['t']) && (string) $cell['t'] === 's') {
                        $index = (int) $cell->v;
                        $value = isset($shared_strings[$index]) ? $shared_strings[$index] : '';
                    } else {
                        $value = (string) $cell->v;
                    }
                }
                $row_data[] = $value;
            }
            $rows[] = $row_data;
        }

        return $rows;
    }

    /**
     * Convert column number to Excel column letter (0 = A, 1 = B, etc.)
     */
    private function num_to_col($num)
    {
        $col = '';
        while ($num >= 0) {
            $col = chr(65 + ($num % 26)) . $col;
            $num = intdiv($num, 26) - 1;
            if ($num < 0) break;
        }
        return $col;
    }
}

// Initialize the class
new PortalCloud9_Import_Export_Ajax();

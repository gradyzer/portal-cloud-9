<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Template file: variables are local to template scope, not truly global.
/**
 * Products Tab Template
 * 
 * @package Portal Cloud 9
 * @version 8.3.5
 */

defined('ABSPATH') || exit;

// Check permissions
if (!PortalCloud9_Config::user_can_manage_products()) {
    echo '<div class="p9-error">You do not have permission to manage products.</div>';
    return;
}

$per_page = PortalCloud9_Config::get_products_per_page();
?>


<div class="p9-products-wrap" data-per-page="<?php echo esc_attr($per_page); ?>">
    <!-- Products Toolbar -->
    <div class="p9-products-toolbar">
        <div class="p9-toolbar-left">
            <input id="p9-search-input" type="search" placeholder="Search products…">
            
            <select id="p9-cat-filter">
                <option value="">All Categories</option>
                <?php
                $cats = get_terms([
                    'taxonomy' => 'product_cat',
                    'hide_empty' => false
                ]);
                foreach ($cats as $cat) {
                    echo '<option value="' . esc_attr($cat->slug) . '">' . esc_html($cat->name) . '</option>';
                }
                ?>
            </select>
            
            <select id="p9-status-filter">
                <option value="any">All Status</option>
                <option value="publish">Published</option>
                <option value="draft">Draft</option>
            </select>
            
            <select id="p9-stock-filter">
                <option value="any">All Stock</option>
                <option value="instock">In Stock</option>
                <option value="outofstock">Out of Stock</option>
                <option value="low">Low Stock</option>
            </select>
            
            <button class="p9-btn-primary" id="p9-apply-filters">Apply</button>
        </div>
        
        <div class="p9-toolbar-right">
            <!-- Import/Export Actions -->
            <button class="p9-import-export-btn" id="p9-export-btn" title="Export products (CSV or XLSX)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Export
            </button>
            
            <button class="p9-import-export-btn" id="p9-import-btn" title="Import products (CSV or XLSX)">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
                Import
            </button>
            <input type="file" id="p9-import-file-input" accept=".csv,.txt,.xlsx" style="display:none;">
        </div>
    </div>
    
    <!-- Select All Bar -->
    <div class="p9-select-all-bar">
        <label class="p9-select-all-wrapper">
            <input id="p9-select-all" type="checkbox">
            <span>Select All</span>
        </label>
        
        <div class="p9-bulk-actions" id="p9-bulk-actions">
            <span class="p9-bulk-count">
                <span id="p9-selected-count">0</span> selected
            </span>
            <button class="p9-btn-bulk" id="p9-bulk-publish">Publish</button>
            <button class="p9-btn-bulk" id="p9-bulk-draft">Draft</button>
            <button class="p9-btn-bulk danger" id="p9-bulk-delete">Delete</button>
        </div>
    </div>
    
    <!-- Loading Indicator -->
    <div class="p9-loading" id="p9-loading" style="display:none">
        Loading products...
    </div>
    
    <!-- Products Container -->
    <div class="p9-products-container list" id="p9-products-container"></div>
    
    <!-- Pagination -->
    <div class="p9-pagination" id="p9-pagination"></div>
    
    <!-- Export Format Modal -->
    <div id="p9-export-format-modal" class="p9-format-modal" style="display:none;">
        <div class="p9-modal-content">
            <span class="p9-modal-close">&times;</span>
            <h3>Select Export Format</h3>
            <p>Choose the file format for exporting your products:</p>
            <div class="p9-format-options">
                <button class="p9-export-format-option" data-format="csv">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <strong>CSV</strong>
                    <span>Comma-separated values<br>Compatible with Excel, Google Sheets</span>
                </button>
                <button class="p9-export-format-option" data-format="xlsx">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <strong>XLSX</strong>
                    <span>Excel format<br>Best for Excel users</span>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Import Format Modal -->
    <div id="p9-import-format-modal" class="p9-format-modal" style="display:none;">
        <div class="p9-modal-content">
            <span class="p9-modal-close">&times;</span>
            <h3>Select Import Format</h3>
            <p>Choose the file format you want to import:</p>
            <div class="p9-format-options">
                <button class="p9-import-format-option" data-format="csv">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <strong>CSV</strong>
                    <span>Comma-separated values<br>Compatible with most apps</span>
                </button>
                <button class="p9-import-format-option" data-format="xlsx">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <strong>XLSX</strong>
                    <span>Excel format<br>Direct from Excel</span>
                </button>
            </div>
        </div>
    </div>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound ?>

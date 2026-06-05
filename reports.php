<?php
require_once 'config.php';
requireLogin();

$pageTitle = "Reports";
$pdo = getDBConnection();

// Handle report generation
$reportData = [];
$reportType = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$vendorFilter = $_GET['vendor'] ?? 'ALL';
$showFilter = $_GET['show'] ?? 'All';

// Bundle Sales specific parameters
$promoNumber     = trim($_GET['promo_number']   ?? '');
$invoiceNumber   = trim($_GET['invoice_number'] ?? '');
$showTotalValues = (isset($_GET['show_total_values']) && in_array($_GET['show_total_values'], ['1','0'])) ? ($_GET['show_total_values'] === '1') : true;
$showUnitPrice   = (isset($_GET['show_unit_price']) && in_array($_GET['show_unit_price'], ['1','0'])) ? ($_GET['show_unit_price'] === '1') : true;
$buyField        = trim($_GET['buy'] ?? '');
$invoiceNull     = isset($_GET['invoice_null']) ? true : false;
$pdfMode         = isset($_GET['pdf']) && $_GET['pdf'] == '1';
$pdfDownload     = isset($_GET['pdf_dl']) && $_GET['pdf_dl'] == '1';

// Helper: Detect available columns in a table
function getCols(PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll();
        $out = [];
        foreach ($rows as $r) { $out[strtoupper($r['Field'])] = true; }
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

// Bundle report renderer (used for screen and PDF)
function renderBundleReportHtml(array $rows, array $totals, string $dateFrom, string $dateTo,
                                string $promoNumber, string $invoiceNumber, bool $showTotalValues, bool $showUnitPrice, string $buyField = ''): string
{
    $titleDates   = date('n/j/Y', strtotime($dateFrom)) . ' - ' . date('n/j/Y', strtotime($dateTo));
    $invoiceLabel = $invoiceNumber !== '' ? 'INVOICE ' . htmlspecialchars($invoiceNumber) : '';
    ob_start();
    ?>
    <div style="font-weight:700;margin-bottom:6px;font-size:15px;">
        ALL STORES - Sales Report For Dates <?php echo $titleDates; ?>
        <span style="float:right;"><?php echo $invoiceLabel; ?></span>
    </div>
    <?php if ($buyField !== ''): ?>
        <div style="margin-bottom:6px;font-weight:600;">BUY: <?php echo htmlspecialchars($buyField); ?></div>
    <?php endif; ?>
    <table class="bundle-table" style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
            <tr>
                <th style="border:1px solid #333;padding:6px;background:#efefef;text-align:left;font-weight:700;">SKU</th>
                <th style="border:1px solid #333;padding:6px;background:#efefef;text-align:left;font-weight:700;">Name</th>
                <th style="border:1px solid #333;padding:6px;background:#efefef;text-align:left;font-weight:700;">Size</th>
                <th style="border:1px solid #333;padding:6px;background:#efefef;text-align:right;font-weight:700;">Reg Price</th>
                <th style="border:1px solid #333;padding:6px;background:#efefef;text-align:right;font-weight:700;">Sale Price</th>
                <th style="border:1px solid #333;padding:6px;background:#efefef;text-align:right;font-weight:700;">Promo Sales</th>
                <th style="border:1px solid #333;padding:6px;background:#efefef;text-align:right;font-weight:700;">Total Sales</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td style="border:1px solid #333;padding:6px;"><?php echo htmlspecialchars((string)($row['sku'] ?? '')); ?></td>
                <td style="border:1px solid #333;padding:6px;"><?php echo htmlspecialchars((string)($row['name'] ?? '')); ?></td>
                <td style="border:1px solid #333;padding:6px;"><?php echo htmlspecialchars((string)($row['size'] ?? '')); ?></td>
                <td style="border:1px solid #333;padding:6px;text-align:right;"><?php echo is_null($row['reg_price']) ? '' : '$' . number_format((float)$row['reg_price'], 2); ?></td>
                <td style="border:1px solid #333;padding:6px;text-align:right;"><?php echo is_null($row['sale_price']) ? '' : '$' . number_format((float)$row['sale_price'], 2); ?></td>
                <td style="border:1px solid #333;padding:6px;text-align:right;"><?php echo number_format((float)($row['promo_units'] ?? 0)); ?></td>
                <td style="border:1px solid #333;padding:6px;text-align:right;"><?php echo number_format((float)($row['total_units'] ?? 0)); ?></td>
            </tr>
        <?php endforeach; ?>
            <tr style="font-weight:700;background:#f6f6f6;">
                <td colspan="4" style="border:1px solid #333;padding:6px;text-align:right;">Totals:</td>
                <td style="border:1px solid #333;padding:6px;"></td>
                <td style="border:1px solid #333;padding:6px;text-align:right;"><?php echo number_format((float)$totals['promo_units']); ?></td>
                <td style="border:1px solid #333;padding:6px;text-align:right;"><?php echo number_format((float)$totals['total_units']); ?></td>
            </tr>
        </tbody>
    </table>
    <div style="margin-top:8px;font-weight:700;">Total dollars Owed (promo $) $<?php echo number_format((float)$totals['promo_amount'], 2); ?></div>
    <div style="margin-top:6px;font-size:11px;color:#444;"><?php echo date('n/j/Y g:i:s A'); ?> &nbsp;&nbsp; Promo #<?php echo htmlspecialchars($promoNumber); ?> <?php if ($invoiceNumber !== ''): ?>&nbsp;&nbsp; Invoice #<?php echo htmlspecialchars($invoiceNumber); ?><?php endif; ?></div>
    <?php
    return ob_get_clean();
}

if ($reportType) {
    switch ($reportType) {
        case 'deal_status_report':
            $whereConditions = ["d.isdeleted = 0"];
            $params = [];
            if ($dateFrom && $dateTo) {
                $whereConditions[] = "d.datestart BETWEEN ? AND ?";
                $params[] = $dateFrom;
                $params[] = $dateTo;
            }
            if ($vendorFilter !== 'ALL') {
                $whereConditions[] = "d.vendor = ?";
                $params[] = $vendorFilter;
            }
            if ($showFilter === 'Submitted Only') {
                $whereConditions[] = "d.dealstatus = 'Submitted'";
            } elseif ($showFilter === 'Completed Only') {
                $whereConditions[] = "d.dealstatus = 'Completed'";
            } elseif ($showFilter === 'Pending Only') {
                $whereConditions[] = "d.dealstatus = 'Pending'";
            }
            $whereClause = implode(' AND ', $whereConditions);
            $stmt = $pdo->prepare("
                SELECT 
                    d.deals_id,
                    d.vendor,
                    COALESCE(v.namelast, '') as sales_last,
                    COALESCE(v.namefirst, '') as sales_first,
                    CONCAT(COALESCE(v.namelast, ''), CASE WHEN v.namelast IS NOT NULL AND v.namefirst IS NOT NULL THEN ' ' ELSE '' END, COALESCE(v.namefirst, '')) as sales_person,
                    d.name as deal_name,
                    d.amtowed * d.itemsrequired as amount,
                    d.datestart as entered_date,
                    d.dealstatus,
                    d.dealstatus as status_name,
                    d.created_at
                FROM deals d
                LEFT JOIN vendor v ON d.vendor = v.salescompany AND v.isdeleted = 0
                WHERE $whereClause
                GROUP BY d.deals_id, d.vendor, v.namelast, v.namefirst, d.name, d.amtowed, d.itemsrequired, d.datestart, d.dealstatus, d.created_at
                ORDER BY d.datestart DESC, d.vendor, d.name
            ");
            $stmt->execute($params);
            $reportData = $stmt->fetchAll();
            break;

        case 'deals_summary':
            $stmt = $pdo->prepare("
                SELECT 
                    d.vendor,
                    COUNT(*) as total_deals,
                    SUM(d.amtowed * d.itemsrequired) as total_amount,
                    SUM(CASE WHEN d.dealstatus = 'Pending' THEN 1 ELSE 0 END) as new_deals,
                    SUM(CASE WHEN d.dealstatus = 'Submitted' THEN 1 ELSE 0 END) as submitted_deals,
                    SUM(CASE WHEN d.dealstatus = 'Completed' THEN 1 ELSE 0 END) as completed_deals
                FROM deals d
                WHERE d.isdeleted = 0 
                    AND d.datestart BETWEEN ? AND ?
                GROUP BY d.vendor
                ORDER BY total_amount DESC
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $reportData = $stmt->fetchAll();
            break;

        case 'vendor_performance':
            $stmt = $pdo->prepare("
                SELECT 
                    d.vendor,
                    YEAR(d.datestart) as year,
                    COUNT(*) as deals_count,
                    SUM(d.amtowed * d.itemsrequired) as total_value,
                    AVG(d.amtowed * d.itemsrequired) as avg_deal_value,
                    MIN(d.datestart) as first_deal,
                    MAX(d.datestart) as last_deal
                FROM deals d
                WHERE d.isdeleted = 0 
                    AND d.datestart BETWEEN ? AND ?
                GROUP BY d.vendor, YEAR(d.datestart)
                ORDER BY d.vendor, year DESC
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $reportData = $stmt->fetchAll();
            break;

        case 'monthly_summary':
            $stmt = $pdo->prepare("
                SELECT 
                    YEAR(d.datestart) as year,
                    MONTH(d.datestart) as month,
                    MONTHNAME(d.datestart) as month_name,
                    COUNT(*) as deals_count,
                    SUM(d.amtowed * d.itemsrequired) as total_amount,
                    COUNT(DISTINCT d.vendor) as unique_vendors
                FROM deals d
                WHERE d.isdeleted = 0 
                    AND d.datestart BETWEEN ? AND ?
                GROUP BY YEAR(d.datestart), MONTH(d.datestart)
                ORDER BY year DESC, month DESC
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $reportData = $stmt->fetchAll();
            break;

        case 'sku_analysis':
            $stmt = $pdo->prepare("
                SELECT 
                    dd.sku,
                    i.name as sku_name,
                    i.category,
                    COUNT(DISTINCT dd.deals_id) as deals_count,
                    SUM(dd.units_sold) as total_quantity,
                    AVG(dd.units_sold) as avg_quantity
                FROM dealdet dd
                LEFT JOIN inventory i ON dd.sku = i.skunumber
                INNER JOIN deals d ON dd.deals_id = d.deals_id
                WHERE d.isdeleted = 0 
                    AND d.datestart BETWEEN ? AND ?
                    AND dd.sku > 0
                GROUP BY dd.sku, i.name, i.category
                HAVING total_quantity > 0
                ORDER BY total_quantity DESC
                LIMIT 50
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $reportData = $stmt->fetchAll();
            break;

        case 'bundle_sales':
            if ($promoNumber === '') { $reportData = []; break; }

            // If invoice number provided, get SKUs from purchases table for that invoice
            $invoiceSKUs = [];
         //   if ($invoiceNumber !== '') {
         //       $stmtInv = $pdo->prepare("SELECT DISTINCT SKUNUMBER FROM purchases WHERE INVOICENUMBER = ?");
         //       $stmtInv->execute([$invoiceNumber]);
         //       $invoiceSKUs = array_column($stmtInv->fetchAll(PDO::FETCH_ASSOC), 'SKUNUMBER');
         //       if (empty($invoiceSKUs)) { $reportData = []; break; }
         //   }

            $invCols = getCols($pdo, 'INVENTORY');
            $hasSize = isset($invCols['SIZE']);
            $hasName = isset($invCols['NAME']);
            if (isset($invCols['SUGGESTED'])) $retailSrc = 'SUGGESTED';
            elseif (isset($invCols['RETAIL'])) $retailSrc = 'RETAIL';
            elseif (isset($invCols['REGPRICE'])) $retailSrc = 'REGPRICE';
            else $retailSrc = null;

            // Inventory aggregated subquery (one row per SKU) - fixes cartesian join
            $invSelect = ['SKUNUMBER'];
            if ($hasName)   $invSelect[] = 'MAX(NAME) AS NAME';
            if ($hasSize)   $invSelect[] = 'MAX(SIZE) AS SIZE';
            if ($retailSrc) $invSelect[] = "MAX($retailSrc) AS REG_FALLBACK";
            $invSubquery = "SELECT " . implode(', ', $invSelect) . " FROM INVENTORY GROUP BY SKUNUMBER";

            // Build SKU filter placeholders if invoice SKUs set
            $skuFilter = '';
            $skuParams = [];
            if (!empty($invoiceSKUs)) {
                $ph = [];
                foreach ($invoiceSKUs as $i => $sku) {
                    $key = ':sku' . $i;
                    $ph[] = $key;
                    $skuParams[$key] = $sku;
                }
                $skuFilter = ' AND SKUNUMBER IN (' . implode(',', $ph) . ')';
            }

            // Promo aggregate (promo rows only)
            $promoAggSql = "
                SELECT
                    SKUNUMBER,
                    AVG(PRICE - COALESCE(MARKDOWN,0) - COALESCE(POSMARKDOWN,0)) AS promo_sale_price,
                    SUM(QUANTITY) AS promo_units,
                    SUM((COALESCE(MARKDOWN,0) + COALESCE(POSMARKDOWN,0)) * QUANTITY) AS promo_amount,
                    COUNT(*) AS promo_lines,
                    AVG(PRICE) AS avg_price_on_promo
                FROM sales
                WHERE DATE(SALEDATE) BETWEEN :sdf AND :sdt AND PROMOTIONID = :pid" . $skuFilter . "
                GROUP BY SKUNUMBER
            ";
			// Dan Added
			$promoRegSql = "
				SELECT
					SKUNUMBER,
					MAX(PRICE) AS promo_reg_price
				FROM sales
				WHERE DATE(SALEDATE) BETWEEN :sdf AND :sdt
				AND PROMOTIONID = :pid" . $skuFilter . "
				GROUP BY SKUNUMBER
			";
            // Total aggregate in date range (all rows)
            $totalAggSql = "
                SELECT
                    SKUNUMBER,
                    SUM(QUANTITY) AS total_units,
                    SUM((PRICE - COALESCE(MARKDOWN,0) - COALESCE(POSMARKDOWN,0)) * QUANTITY) AS total_amount
                FROM sales
                WHERE DATE(SALEDATE) BETWEEN :sdf AND :sdt" . $skuFilter . "
                GROUP BY SKUNUMBER
            ";

            $sql = "
				SELECT
					p.SKUNUMBER AS sku,
					COALESCE(MAX(inv.NAME), MAX(s.DESCRIPTION)) AS name,
					" . ($hasSize ? "MAX(inv.SIZE)" : "''") . " AS size,
					COALESCE(r.promo_reg_price, MAX(inv.REG_FALLBACK)) AS reg_price,
					p.promo_sale_price AS sale_price,
					p.promo_units AS promo_units,
					COALESCE(t.total_units, 0) AS total_units,
					p.promo_amount AS promo_amount,
					COALESCE(t.total_amount, 0) AS total_amount,
					p.promo_lines
					FROM (" . $promoAggSql . ") p
				LEFT JOIN (" . $totalAggSql . ") t ON t.SKUNUMBER = p.SKUNUMBER
				LEFT JOIN (" . $promoRegSql . ") r ON r.SKUNUMBER = p.SKUNUMBER
				LEFT JOIN (" . $invSubquery . ") inv ON inv.SKUNUMBER = p.SKUNUMBER
				LEFT JOIN sales s ON s.SKUNUMBER = p.SKUNUMBER
				GROUP BY p.SKUNUMBER
				ORDER BY p.SKUNUMBER
";

            $stmt = $pdo->prepare($sql);
            $bind = [':sdf' => $dateFrom, ':sdt' => $dateTo, ':pid' => $promoNumber];
            foreach ($skuParams as $k => $v) $bind[$k] = $v;
            $stmt->execute($bind);
            $reportData = $stmt->fetchAll();
            break;
    }
}

// Get vendors for filter dropdown
$vendors = $pdo->query("
    SELECT DISTINCT vendor as salescompany
    FROM deals 
    WHERE isdeleted = 0 
    ORDER BY vendor
")->fetchAll();

// Compute bundle totals
$bundleTotals = ['promo_units'=>0,'promo_amount'=>0.0,'total_units'=>0,'total_amount'=>0.0];
if ($reportType === 'bundle_sales') {
    foreach ($reportData as $r) {
        $bundleTotals['promo_units']  += (float)$r['promo_units'];
        $bundleTotals['promo_amount'] += (float)$r['promo_amount'];
        $bundleTotals['total_units']  += (float)$r['total_units'];
        $bundleTotals['total_amount'] += (float)$r['total_amount'];
    }
}

// DOMPDF download support
if ($reportType === 'bundle_sales' && $pdfDownload) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
    if (class_exists('Dompdf\\Dompdf')) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        $bodyHtml = renderBundleReportHtml($reportData, $bundleTotals, $dateFrom, $dateTo, $promoNumber, $invoiceNumber, $showTotalValues, $showUnitPrice, $buyField);
        $css = 'body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color:#000; }';
        $html = "<!DOCTYPE html><html><head><meta charset='utf-8'><style>$css</style></head><body>$bodyHtml</body></html>";
        $dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('Letter', 'landscape');
        $dompdf->render();
        $fname = 'BundleSales_Promo' . preg_replace('/[^A-Za-z0-9_-]/', '', $promoNumber) . '_' . date('Ymd_His') . '.pdf';
        $dompdf->stream($fname, ['Attachment' => true]);
        exit;
    } else {
        $qs = $_GET; unset($qs['pdf_dl']); $qs['pdf'] = '1';
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($qs));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Broudy's Reporting - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        .report-selector { background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
        .report-buttons { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .report-button { display: block; padding: 15px; background-color: #fff; border: 2px solid #ddd; border-radius: 8px; text-decoration: none; color: #333; text-align: center; transition: all 0.3s; }
        .report-button:hover, .report-button.active { border-color: #000; background-color: #000; color: #fff; }
        .report-button i { font-size: 24px; margin-bottom: 10px; display: block; }
        .export-buttons { margin: 20px 0; text-align: right; }
        .export-buttons button { margin-left: 10px; }
        .deal-status-filters, .bundle-sales-filters { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .deal-status-filters .filters-row { display: grid; grid-template-columns: 200px 200px 150px 150px 150px auto; gap: 15px; align-items: end; }
        .bundle-sales-filters .filters-row { display: grid; grid-template-columns: 140px 140px 150px 180px 130px 130px 90px auto; gap: 12px; align-items: start; }
        .bundle-sales-filters .form-col { display: flex; flex-direction: column; }
        .bundle-sales-filters label { display:block; font-weight:600; margin-bottom:4px; font-size: 13px; }
        .bundle-sales-filters input[type="text"], .bundle-sales-filters input[type="date"], .bundle-sales-filters input[type="number"], .bundle-sales-filters select {
            width: 100%; padding: 6px 8px; border: 1px solid #ced4da; border-radius: 4px; box-sizing: border-box; height: 34px;
        }
        .bundle-sales-filters .invoice-row { display: flex; gap: 6px; align-items: center; }
        .bundle-sales-filters .invoice-row input[type="text"] { flex: 1; }
        .bundle-sales-filters .invoice-row .none-check { display: flex; align-items: center; gap: 3px; white-space: nowrap; font-weight: 400; font-size: 12px; cursor: pointer; margin: 0; }
        .bundle-sales-filters .invoice-row .none-check input { margin: 0; }
        .bundle-sales-filters .radio-group { display: flex; gap: 10px; height: 34px; align-items: center; }
        .bundle-sales-filters .radio-group label { font-weight: 400; margin: 0; cursor: pointer; font-size: 13px; }
        .bundle-sales-filters .radio-group input { margin-right: 3px; }
        .bundle-sales-filters .action-buttons { display: flex; gap: 4px; align-items: center; height: 34px; }
        .data-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .data-table th { background-color: #f8f9fa; font-weight: 700; }
        .status-complete { color: #28a745; font-weight: bold; }
        .status-submitted { color: #ffc107; font-weight: bold; }
        .status-pending { color: #dc3545; font-weight: bold; }
        @media (max-width: 900px) {
            .deal-status-filters .filters-row, .bundle-sales-filters .filters-row { grid-template-columns: 1fr; }
        }
        @media print {
            .no-print, .top-bar, .report-selector, .bundle-sales-filters, .deal-status-filters, .export-buttons { display: none !important; }
            body { background: #fff; }
            @page { size: Letter landscape; margin: 0.5in; }
        }
    </style>
</head>
<body class="<?php echo ($reportType === 'bundle_sales' && $pdfMode) ? 'pdf-view' : ''; ?>">
    <?php include 'header.php'; ?>

    <div class="main-container">
        <div class="content-card">
            <?php if (!$pdfMode): ?>
            <h1>Reports Dashboard</h1>

            <div class="report-selector">
                <h3>Select Report Type</h3>
                <div class="report-buttons">
                    <a href="?type=deal_status_report&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>&vendor=<?php echo $vendorFilter; ?>&show=<?php echo $showFilter; ?>" class="report-button <?php echo $reportType === 'deal_status_report' ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice-dollar"></i><strong>Deal Status Report</strong><br>Complete deal tracking and status
                    </a>
                    <a href="?type=deals_summary&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="report-button <?php echo $reportType === 'deals_summary' ? 'active' : ''; ?>">
                        <i class="fas fa-handshake"></i><strong>Deals Summary</strong><br>Overview of all deals by vendor
                    </a>
                    <a href="?type=vendor_performance&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="report-button <?php echo $reportType === 'vendor_performance' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i><strong>Vendor Performance</strong><br>Year-over-year vendor analysis
                    </a>
                    <a href="?type=monthly_summary&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="report-button <?php echo $reportType === 'monthly_summary' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i><strong>Monthly Summary</strong><br>Monthly deals breakdown
                    </a>
                    <a href="?type=sku_analysis&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="report-button <?php echo $reportType === 'sku_analysis' ? 'active' : ''; ?>">
                        <i class="fas fa-box"></i><strong>SKU Analysis</strong><br>Top performing SKUs
                    </a>
                    <a href="?type=bundle_sales&date_from=<?php echo $dateFrom; ?>&date_to=<?php echo $dateTo; ?>" class="report-button <?php echo $reportType === 'bundle_sales' ? 'active' : ''; ?>">
                        <i class="fas fa-tags"></i><strong>Bundle Sales</strong><br>Sales by promotion / bundle
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($reportType === 'deal_status_report'): ?>
                <div class="deal-status-filters">
                    <form method="GET">
                        <input type="hidden" name="type" value="deal_status_report">
                        <div class="filters-row">
                            <div class="form-group">
                                <label>Company/Sales:</label>
                                <select name="vendor">
                                    <option value="ALL" <?php echo $vendorFilter === 'ALL' ? 'selected' : ''; ?>>All Vendors</option>
                                    <?php foreach ($vendors as $vendor): ?>
                                        <option value="<?php echo htmlspecialchars($vendor['salescompany']); ?>" <?php echo $vendorFilter === $vendor['salescompany'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($vendor['salescompany']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Show:</label>
                                <select name="show">
                                    <option value="All" <?php echo $showFilter === 'All' ? 'selected' : ''; ?>>All</option>
                                    <option value="Submitted Only" <?php echo $showFilter === 'Submitted Only' ? 'selected' : ''; ?>>Submitted Only</option>
                                    <option value="Completed Only" <?php echo $showFilter === 'Completed Only' ? 'selected' : ''; ?>>Completed Only</option>
                                    <option value="Pending Only" <?php echo $showFilter === 'Pending Only' ? 'selected' : ''; ?>>Pending Only</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Start Date:</label>
                                <input type="date" name="date_from" value="<?php echo $dateFrom; ?>">
                            </div>
                            <div class="form-group">
                                <label>End Date:</label>
                                <input type="date" name="date_to" value="<?php echo $dateTo; ?>">
                            </div>
                            <div class="form-group">
                                <button type="submit" class="btn btn-success"><i class="fas fa-search"></i> Search</button>
                            </div>
                            <div class="form-group">
                                <button type="button" onclick="window.print()" class="btn" style="background-color: #6c757d;"><i class="fas fa-print"></i></button>
                                <button type="button" onclick="exportToCSV()" class="btn" style="background-color: #28a745;"><i class="fas fa-file-excel"></i></button>
                            </div>
                        </div>
                    </form>
                </div>

            <?php elseif ($reportType === 'bundle_sales' && !$pdfMode): ?>
                <div class="bundle-sales-filters">
                    <form method="GET">
                        <input type="hidden" name="type" value="bundle_sales">
                        <div class="filters-row">
                            <div class="form-col">
                                <label>Start Date <span style="color:#c00">*</span></label>
                                <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" required>
                            </div>
                            <div class="form-col">
                                <label>End Date <span style="color:#c00">*</span></label>
                                <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" required>
                            </div>
                            <div class="form-col">
                                <label>Promo Number <span style="color:#c00">*</span></label>
                                <input type="text" name="promo_number" value="<?php echo htmlspecialchars($promoNumber); ?>" required>
                            </div>
                            <div class="form-col">
                                <label>Invoice Number</label>
                                <div class="invoice-row">
                                    <input type="text" name="invoice_number" id="invoice_number" value="<?php echo htmlspecialchars($invoiceNumber); ?>" <?php echo $invoiceNull ? 'disabled' : ''; ?>>
                                    <label class="none-check"><input type="checkbox" name="invoice_null" id="invoice_null" value="1" <?php echo $invoiceNull ? 'checked' : ''; ?> onchange="toggleInvoiceField(this)"> NONE</label>
                                </div>
                            </div>
                            <div class="form-col">
                                <label>Show Unit Price</label>
                                <div class="radio-group">
                                    <label><input type="radio" name="show_unit_price" value="1" <?php echo $showUnitPrice ? 'checked' : ''; ?>> Yes</label>
                                    <label><input type="radio" name="show_unit_price" value="0" <?php echo !$showUnitPrice ? 'checked' : ''; ?>> No</label>
                                </div>
                            </div>
                            <div class="form-col">
                                <label>Show Total Values</label>
                                <div class="radio-group">
                                    <label><input type="radio" name="show_total_values" value="1" <?php echo $showTotalValues ? 'checked' : ''; ?>> Yes</label>
                                    <label><input type="radio" name="show_total_values" value="0" <?php echo !$showTotalValues ? 'checked' : ''; ?>> No</label>
                                </div>
                            </div>
                            <div class="form-col">
                                <label>Buy</label>
                                <input type="number" step="1" name="buy" value="<?php echo htmlspecialchars($buyField); ?>" placeholder="Buy">
                            </div>
                            <div class="form-col">
                                <label>&nbsp;</label>
                                <div class="action-buttons">
                                    <button type="submit" class="btn btn-success" style="padding:6px 10px;" title="Search"><i class="fas fa-search"></i></button>
                                    <button type="button" onclick="exportBundleToPDF()" class="btn" style="background:#dc3545;color:#fff;padding:6px 10px;" title="Export PDF"><i class="fas fa-file-pdf"></i></button>
                                    <button type="button" onclick="exportToCSV()" class="btn" style="background:#28a745;color:#fff;padding:6px 10px;" title="Export CSV"><i class="fas fa-file-excel"></i></button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($reportType && !empty($reportData)): ?>
                <div id="reportContent">
                    <?php if ($reportType === 'deal_status_report'): ?>
                        <table class="data-table deal-status-table">
                            <thead>
                                <tr>
                                    <th>Vendor</th><th>Sales</th><th>Name</th><th>Amount</th><th>Entered</th><th>Complete</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $totalAmount = 0;
                                foreach ($reportData as $row):
                                    $totalAmount += $row['amount'];
                                    $statusClass = '';
                                    switch($row['dealstatus']) {
                                        case 'Completed': $statusClass = 'status-complete'; break;
                                        case 'Submitted': $statusClass = 'status-submitted'; break;
                                        default: $statusClass = 'status-pending'; break;
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['vendor']); ?></td>
                                        <td><?php echo htmlspecialchars($row['sales_person']); ?></td>
                                        <td><?php echo htmlspecialchars($row['deal_name']); ?></td>
                                        <td>$<?php echo number_format($row['amount'], 2); ?></td>
                                        <td><?php echo date('m/d/Y', strtotime($row['entered_date'])); ?></td>
                                        <td><span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars($row['status_name']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="background-color: #6ba5ad; color: #fff; font-weight: bold;">
                                    <td colspan="3">TOTAL</td>
                                    <td>$<?php echo number_format($totalAmount, 2); ?></td>
                                    <td colspan="2"><?php echo count($reportData); ?> deals</td>
                                </tr>
                            </tbody>
                        </table>

                    <?php elseif ($reportType === 'deals_summary'): ?>
                        <h3>Deals Summary by Vendor (<?php echo date('m/d/Y', strtotime($dateFrom)); ?> - <?php echo date('m/d/Y', strtotime($dateTo)); ?>)</h3>
                        <table class="data-table">
                            <thead><tr><th>Vendor</th><th>Total Deals</th><th>Total Amount</th><th>Pending</th><th>Submitted</th><th>Completed</th></tr></thead>
                            <tbody>
                                <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['vendor']); ?></td>
                                        <td><?php echo number_format($row['total_deals']); ?></td>
                                        <td>$<?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td><?php echo number_format($row['new_deals']); ?></td>
                                        <td><?php echo number_format($row['submitted_deals']); ?></td>
                                        <td><?php echo number_format($row['completed_deals']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    <?php elseif ($reportType === 'vendor_performance'): ?>
                        <h3>Vendor Performance (<?php echo date('m/d/Y', strtotime($dateFrom)); ?> - <?php echo date('m/d/Y', strtotime($dateTo)); ?>)</h3>
                        <table class="data-table">
                            <thead><tr><th>Vendor</th><th>Year</th><th>Deals</th><th>Total Value</th><th>Avg Deal</th><th>First Deal</th><th>Last Deal</th></tr></thead>
                            <tbody>
                                <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['vendor']); ?></td>
                                        <td><?php echo $row['year']; ?></td>
                                        <td><?php echo number_format($row['deals_count']); ?></td>
                                        <td>$<?php echo number_format($row['total_value'], 2); ?></td>
                                        <td>$<?php echo number_format($row['avg_deal_value'], 2); ?></td>
                                        <td><?php echo date('m/d/Y', strtotime($row['first_deal'])); ?></td>
                                        <td><?php echo date('m/d/Y', strtotime($row['last_deal'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    <?php elseif ($reportType === 'monthly_summary'): ?>
                        <h3>Monthly Summary</h3>
                        <table class="data-table">
                            <thead><tr><th>Year</th><th>Month</th><th>Deal Count</th><th>Total Amount</th><th>Unique Vendors</th><th>Avg per Deal</th></tr></thead>
                            <tbody>
                                <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td><?php echo $row['year']; ?></td>
                                        <td><?php echo $row['month_name']; ?></td>
                                        <td><?php echo number_format($row['deals_count']); ?></td>
                                        <td>$<?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td><?php echo number_format($row['unique_vendors']); ?></td>
                                        <td>$<?php echo number_format($row['total_amount'] / max(1,$row['deals_count']), 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    <?php elseif ($reportType === 'sku_analysis'): ?>
                        <h3>SKU Analysis Report - Top 50 SKUs (<?php echo date('m/d/Y', strtotime($dateFrom)); ?> - <?php echo date('m/d/Y', strtotime($dateTo)); ?>)</h3>
                        <table class="data-table">
                            <thead><tr><th>SKU</th><th>Name</th><th>Category</th><th>Deal Count</th><th>Total Quantity</th><th>Avg Quantity</th></tr></thead>
                            <tbody>
                                <?php foreach ($reportData as $row): ?>
                                    <tr>
                                        <td><?php echo $row['sku']; ?></td>
                                        <td><?php echo htmlspecialchars($row['sku_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['category']); ?></td>
                                        <td><?php echo number_format($row['deals_count']); ?></td>
                                        <td><?php echo number_format($row['total_quantity']); ?></td>
                                        <td><?php echo number_format($row['avg_quantity'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    <?php elseif ($reportType === 'bundle_sales'): ?>
                        <?php if ($pdfMode): ?>
                            <div class="pdf-instructions no-print" style="background:#fff3cd;border:1px solid #ffeeba;padding:8px;margin-bottom:10px;border-radius:4px;">
                                <strong>Save as PDF:</strong> Press <kbd>Ctrl</kbd>+<kbd>P</kbd> (or <kbd>Cmd</kbd>+<kbd>P</kbd>), then choose "Save as PDF".
                            </div>
                        <?php endif; ?>
                        <?php echo renderBundleReportHtml($reportData, $bundleTotals, $dateFrom, $dateTo, $promoNumber, $invoiceNumber, $showTotalValues, $showUnitPrice, $buyField); ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($reportType): ?>
                <div class="alert alert-error">No data found for the selected criteria.</div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleInvoiceField(checkbox) {
            const invField = document.getElementById('invoice_number');
            if (!invField) return;
            if (checkbox.checked) {
                invField.value = '';
                invField.disabled = true;
            } else {
                invField.disabled = false;
                invField.focus();
            }
        }

        function exportToCSV() {
            const table = document.querySelector('.bundle-table') || document.querySelector('.data-table');
            if (!table) { alert('No table data to export'); return; }
            let csv = [];
            table.querySelectorAll('tr').forEach(tr => {
                const cells = tr.querySelectorAll('th, td');
                const rowData = Array.from(cells).map(cell => {
                    let t = cell.textContent.trim().replace(/\s+/g, ' ');
                    if (t.includes(',') || t.includes('"')) t = '"' + t.replace(/"/g, '""') + '"';
                    return t;
                });
                csv.push(rowData.join(','));
            });
            const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'broudys_report_' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        function exportBundleToPDF() {
            // Submit form with pdf_dl flag
            const form = document.querySelector('.bundle-sales-filters form');
            if (!form) return;
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'pdf_dl'; input.value = '1';
            form.appendChild(input);
            form.submit();
        }

        <?php if ($reportType === 'bundle_sales' && $pdfMode): ?>
        window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 300); });
        <?php endif; ?>
    </script>
</body>
</html>

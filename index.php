<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$allowedPages = ['dashboard', 'products', 'customers', 'sales', 'purchases', 'reports'];
$page = $_GET['page'] ?? 'dashboard';
if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(mixed $value): string
{
    return 'GH₵' . number_format((float) $value, 2);
}

function app_url(string $page, array $params = []): string
{
    $params = array_merge(['page' => $page], $params);
    return 'index.php?' . http_build_query($params);
}

function pdo(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['database'],
        $config['charset']
    );
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function rows(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function row(PDO $db, string $sql, array $params = []): ?array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    return $result === false ? null : $result;
}

function scalar(PDO $db, string $sql, array $params = []): mixed
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function badge(string $text, string $type = 'neutral'): string
{
    return '<span class="badge badge-' . h($type) . '">' . h($text) . '</span>';
}

function page_header(string $eyebrow, string $title, string $subtitle, ?string $actionLabel = null, ?string $actionUrl = null): string
{
    ob_start();
    ?>
    <section class="page-head">
        <div class="page-copy">
            <p class="eyebrow"><?= h($eyebrow) ?></p>
            <h1><?= h($title) ?></h1>
            <p><?= h($subtitle) ?></p>
        </div>
        <div class="head-actions">
            <span class="status-pill">Live MySQL</span>
            <span class="date-pill"><?= h(date('M j, Y')) ?></span>
            <?php if ($actionLabel && $actionUrl): ?>
                <a class="button" href="<?= h($actionUrl) ?>"><?= h($actionLabel) ?></a>
            <?php endif; ?>
        </div>
    </section>
    <?php
    return (string) ob_get_clean();
}

function kpi_card(string $label, string $value, string $note, string $icon, string $tone = 'blue'): string
{
    ob_start();
    ?>
    <article class="kpi kpi-<?= h($tone) ?>">
        <span class="kpi-icon"><?= h($icon) ?></span>
        <span class="kpi-label"><?= h($label) ?></span>
        <strong><?= h($value) ?></strong>
        <small><?= h($note) ?></small>
    </article>
    <?php
    return (string) ob_get_clean();
}

function table_html(array $headers, array $records, callable $rowRenderer): string
{
    ob_start();
    ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $header): ?>
                        <th><?= h($header) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$records): ?>
                    <tr><td colspan="<?= count($headers) ?>" class="empty">No records found.</td></tr>
                <?php endif; ?>
                <?php foreach ($records as $record): ?>
                    <tr><?= $rowRenderer($record) ?></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return (string) ob_get_clean();
}

function handle_record_sale(PDO $db): array
{
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $employeeId = (int) ($_POST['employee_id'] ?? 0);
    $productId = (int) ($_POST['product_id'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'Cash'));
    $allowedMethods = ['Cash', 'Mobile Money', 'Card', 'Bank Transfer'];

    if ($customerId <= 0 || $employeeId <= 0 || $productId <= 0 || $quantity <= 0) {
        return ['type' => 'error', 'message' => 'Please choose a customer, employee, product, and a quantity greater than zero.'];
    }
    if (!in_array($paymentMethod, $allowedMethods, true)) {
        return ['type' => 'error', 'message' => 'Please choose a valid payment method.'];
    }

    try {
        $db->beginTransaction();

        $customerExists = scalar($db, 'SELECT COUNT(*) FROM customers WHERE customer_id = ?', [$customerId]);
        $employeeExists = scalar($db, 'SELECT COUNT(*) FROM employees WHERE employee_id = ?', [$employeeId]);
        if ((int) $customerExists !== 1 || (int) $employeeExists !== 1) {
            throw new RuntimeException('Selected customer or employee does not exist.');
        }

        $product = row(
            $db,
            'SELECT product_id, product_name, unit_price, quantity_in_stock FROM products WHERE product_id = ? FOR UPDATE',
            [$productId]
        );
        if (!$product) {
            throw new RuntimeException('Selected product does not exist.');
        }
        if ($quantity > (int) $product['quantity_in_stock']) {
            throw new RuntimeException('Not enough stock. Available quantity is ' . (int) $product['quantity_in_stock'] . '.');
        }

        $unitPrice = (float) $product['unit_price'];
        $lineTotal = round($unitPrice * $quantity, 2);

        $stmt = $db->prepare('
            INSERT INTO sales (customer_id, employee_id, sale_date, total_amount)
            VALUES (?, ?, NOW(), ?)
        ');
        $stmt->execute([$customerId, $employeeId, $lineTotal]);
        $saleId = (int) $db->lastInsertId();

        $stmt = $db->prepare('
            INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, line_total)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$saleId, $productId, $quantity, $unitPrice, $lineTotal]);

        $stmt = $db->prepare('
            INSERT INTO payments (sale_id, payment_date, amount_paid, payment_method)
            VALUES (?, NOW(), ?, ?)
        ');
        $stmt->execute([$saleId, $lineTotal, $paymentMethod]);

        $stmt = $db->prepare('
            UPDATE products
            SET quantity_in_stock = quantity_in_stock - ?
            WHERE product_id = ?
        ');
        $stmt->execute([$quantity, $productId]);

        $db->commit();
        return [
            'type' => 'success',
            'message' => 'Sale #' . $saleId . ' recorded. Stock updated for ' . $product['product_name'] . '.',
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['type' => 'error', 'message' => $e->getMessage()];
    }
}

function render_dashboard(PDO $db): string
{
    $stats = [
        ['Sales Total', money(scalar($db, 'SELECT COALESCE(SUM(total_amount), 0) FROM sales')), 'Revenue recorded from live sale rows', '₵', 'emerald'],
        ['Products', scalar($db, 'SELECT COUNT(*) FROM products'), 'Items available for sale', '▦', 'blue'],
        ['Customers', scalar($db, 'SELECT COUNT(*) FROM customers'), 'Registered shoppers', '◉', 'violet'],
        ['Suppliers', scalar($db, 'SELECT COUNT(*) FROM suppliers'), 'Active supply partners', '↗', 'amber'],
        ['Purchase Total', money(scalar($db, 'SELECT COALESCE(SUM(total_cost), 0) FROM purchases')), 'Stock purchase cost', '⇄', 'slate'],
        ['Low Stock', scalar($db, 'SELECT COUNT(*) FROM products WHERE quantity_in_stock <= reorder_level'), 'Products needing attention', '!', 'rose'],
    ];

    $recentSales = rows($db, '
        SELECT s.sale_id, s.sale_date, s.total_amount, c.full_name AS customer_name, e.full_name AS employee_name
        FROM sales s
        JOIN customers c ON c.customer_id = s.customer_id
        JOIN employees e ON e.employee_id = s.employee_id
        ORDER BY s.sale_date DESC, s.sale_id DESC
        LIMIT 6
    ');
    $lowStock = rows($db, '
        SELECT p.product_name, c.category_name, p.quantity_in_stock, p.reorder_level
        FROM products p
        JOIN categories c ON c.category_id = p.category_id
        WHERE p.quantity_in_stock <= p.reorder_level
        ORDER BY p.quantity_in_stock ASC, p.product_name
    ');
    $paymentSummary = rows($db, '
        SELECT payment_method, COUNT(*) AS payment_count, SUM(amount_paid) AS total_paid
        FROM payments
        GROUP BY payment_method
        ORDER BY total_paid DESC
    ');
    $stockValue = scalar($db, 'SELECT COALESCE(SUM(unit_price * quantity_in_stock), 0) FROM products');
    $saleCount = scalar($db, 'SELECT COUNT(*) FROM sales');
    $averageSale = scalar($db, 'SELECT COALESCE(AVG(total_amount), 0) FROM sales');

    ob_start();
    ?>
    <?= page_header(
        'Live database overview',
        'Sunrise Mini Mart Dashboard',
        'A polished view of products, sales, payments, suppliers, and stock movement powered by MySQL.',
        'Record Sale',
        app_url('sales')
    ) ?>
    <section class="kpi-grid">
        <?php foreach ($stats as [$label, $value, $note, $icon, $tone]): ?>
            <?= kpi_card($label, (string) $value, $note, $icon, $tone) ?>
        <?php endforeach; ?>
    </section>
    <section class="pulse-grid">
        <article class="pulse-card">
            <p class="eyebrow">Business pulse</p>
            <h2>Today’s operating picture</h2>
            <div class="pulse-list">
                <div><span>Sales recorded</span><strong><?= h($saleCount) ?></strong></div>
                <div><span>Average sale</span><strong><?= money($averageSale) ?></strong></div>
                <div><span>Retail stock value</span><strong><?= money($stockValue) ?></strong></div>
            </div>
        </article>
        <article class="pulse-card">
            <p class="eyebrow">Payment mix</p>
            <h2>Money collected</h2>
            <div class="payment-stack">
                <?php foreach ($paymentSummary as $payment): ?>
                    <div>
                        <span><?= h($payment['payment_method']) ?></span>
                        <strong><?= money($payment['total_paid']) ?></strong>
                        <small><?= h($payment['payment_count']) ?> payment(s)</small>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>
    <section class="split">
        <article>
            <h2>Recent Sales</h2>
            <?= table_html(['Sale', 'Customer', 'Employee', 'Date', 'Total'], $recentSales, function (array $sale): string {
                return '<td>#' . h($sale['sale_id']) . '</td>'
                    . '<td>' . h($sale['customer_name']) . '</td>'
                    . '<td>' . h($sale['employee_name']) . '</td>'
                    . '<td>' . h($sale['sale_date']) . '</td>'
                    . '<td class="num">' . money($sale['total_amount']) . '</td>';
            }) ?>
        </article>
        <article>
            <h2>Low-Stock Watch</h2>
            <?= table_html(['Product', 'Category', 'Stock', 'Reorder'], $lowStock, function (array $product): string {
                return '<td>' . h($product['product_name']) . '</td>'
                    . '<td>' . h($product['category_name']) . '</td>'
                    . '<td class="num">' . h($product['quantity_in_stock']) . '</td>'
                    . '<td class="num">' . h($product['reorder_level']) . '</td>';
            }) ?>
        </article>
    </section>
    <?php
    return (string) ob_get_clean();
}

function render_products(PDO $db): string
{
    $products = rows($db, '
        SELECT p.*, c.category_name, s.supplier_name
        FROM products p
        JOIN categories c ON c.category_id = p.category_id
        JOIN suppliers s ON s.supplier_id = p.supplier_id
        ORDER BY c.category_name, p.product_name
    ');

    ob_start();
    ?>
    <?= page_header('Inventory', 'Products and Stock Levels', 'Track pricing, suppliers, categories, reorder points, and stock availability from the products table.') ?>
    <?= table_html(['Product', 'Category', 'Supplier', 'Unit Price', 'In Stock', 'Reorder', 'Status'], $products, function (array $product): string {
        $low = (int) $product['quantity_in_stock'] <= (int) $product['reorder_level'];
        return '<td>' . h($product['product_name']) . '</td>'
            . '<td>' . h($product['category_name']) . '</td>'
            . '<td>' . h($product['supplier_name']) . '</td>'
            . '<td class="num">' . money($product['unit_price']) . '</td>'
            . '<td class="num">' . h($product['quantity_in_stock']) . '</td>'
            . '<td class="num">' . h($product['reorder_level']) . '</td>'
            . '<td>' . ($low ? badge('Reorder', 'warning') : badge('OK', 'success')) . '</td>';
    }) ?>
    <?php
    return (string) ob_get_clean();
}

function render_customers(PDO $db): string
{
    $customers = rows($db, '
        SELECT c.*, COUNT(s.sale_id) AS sale_count, COALESCE(SUM(s.total_amount), 0) AS total_spent
        FROM customers c
        LEFT JOIN sales s ON s.customer_id = c.customer_id
        GROUP BY c.customer_id, c.full_name, c.phone, c.email, c.address
        ORDER BY c.full_name
    ');

    ob_start();
    ?>
    <?= page_header('People', 'Customers', 'Customer records connected to sales history, contact details, and total spending.') ?>
    <?= table_html(['Customer', 'Phone', 'Email', 'Address', 'Sales', 'Total Spent'], $customers, function (array $customer): string {
        return '<td>' . h($customer['full_name']) . '</td>'
            . '<td>' . h($customer['phone']) . '</td>'
            . '<td>' . h($customer['email']) . '</td>'
            . '<td>' . h($customer['address']) . '</td>'
            . '<td class="num">' . h($customer['sale_count']) . '</td>'
            . '<td class="num">' . money($customer['total_spent']) . '</td>';
    }) ?>
    <?php
    return (string) ob_get_clean();
}

function render_sales(PDO $db, ?array $notice): string
{
    $customers = rows($db, 'SELECT customer_id, full_name FROM customers ORDER BY full_name');
    $employees = rows($db, 'SELECT employee_id, full_name, role FROM employees ORDER BY full_name');
    $products = rows($db, '
        SELECT product_id, product_name, unit_price, quantity_in_stock
        FROM products
        WHERE quantity_in_stock > 0
        ORDER BY product_name
    ');
    $sales = rows($db, '
        SELECT
            s.sale_id,
            s.sale_date,
            s.total_amount,
            c.full_name AS customer_name,
            e.full_name AS employee_name,
            COALESCE(SUM(p.amount_paid), 0) AS amount_paid,
            GROUP_CONCAT(CONCAT(pr.product_name, " x", si.quantity) ORDER BY pr.product_name SEPARATOR ", ") AS items
        FROM sales s
        JOIN customers c ON c.customer_id = s.customer_id
        JOIN employees e ON e.employee_id = s.employee_id
        JOIN sale_items si ON si.sale_id = s.sale_id
        JOIN products pr ON pr.product_id = si.product_id
        LEFT JOIN payments p ON p.sale_id = s.sale_id
        GROUP BY s.sale_id, s.sale_date, s.total_amount, c.full_name, e.full_name
        ORDER BY s.sale_date DESC, s.sale_id DESC
    ');

    ob_start();
    ?>
    <?= page_header('Transactions', 'Sales', 'Record new sales with database transactions that insert sale, item, payment, and stock updates.') ?>
    <?php if ($notice): ?>
        <div class="notice notice-<?= h($notice['type']) ?>"><?= h($notice['message']) ?></div>
    <?php endif; ?>
    <section class="form-panel">
        <div class="form-head">
            <div>
                <p class="eyebrow">Point of sale</p>
                <h2>Record Sale</h2>
            </div>
            <span class="status-pill">Transaction safe</span>
        </div>
        <form method="post" action="<?= h(app_url('sales')) ?>">
            <input type="hidden" name="action" value="record_sale">
            <label>
                Customer
                <select name="customer_id" required>
                    <option value="">Choose customer</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= h($customer['customer_id']) ?>"><?= h($customer['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Employee
                <select name="employee_id" required>
                    <option value="">Choose employee</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= h($employee['employee_id']) ?>"><?= h($employee['full_name']) ?> (<?= h($employee['role']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Product
                <select name="product_id" required>
                    <option value="">Choose product</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= h($product['product_id']) ?>">
                            <?= h($product['product_name']) ?> - <?= money($product['unit_price']) ?>, stock <?= h($product['quantity_in_stock']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Quantity
                <input type="number" name="quantity" min="1" value="1" required>
            </label>
            <label>
                Payment Method
                <select name="payment_method" required>
                    <option>Cash</option>
                    <option>Mobile Money</option>
                    <option>Card</option>
                    <option>Bank Transfer</option>
                </select>
            </label>
            <button class="button" type="submit">Save Sale</button>
        </form>
    </section>
    <div class="section-title">
        <p class="eyebrow">Ledger</p>
        <h2>Sales Records</h2>
    </div>
    <?= table_html(['Sale', 'Customer', 'Employee', 'Items', 'Paid', 'Status', 'Date'], $sales, function (array $sale): string {
        $paid = (float) $sale['amount_paid'];
        $total = (float) $sale['total_amount'];
        $status = $paid >= $total ? badge('Paid', 'success') : badge('Partial', 'warning');
        return '<td>#' . h($sale['sale_id']) . '<br><strong>' . money($total) . '</strong></td>'
            . '<td>' . h($sale['customer_name']) . '</td>'
            . '<td>' . h($sale['employee_name']) . '</td>'
            . '<td>' . h($sale['items']) . '</td>'
            . '<td class="num">' . money($paid) . '</td>'
            . '<td>' . $status . '</td>'
            . '<td>' . h($sale['sale_date']) . '</td>';
    }) ?>
    <?php
    return (string) ob_get_clean();
}

function render_purchases(PDO $db): string
{
    $purchases = rows($db, '
        SELECT
            p.purchase_id,
            p.purchase_date,
            p.total_cost,
            s.supplier_name,
            e.full_name AS employee_name,
            GROUP_CONCAT(CONCAT(pr.product_name, " x", pi.quantity) ORDER BY pr.product_name SEPARATOR ", ") AS items
        FROM purchases p
        JOIN suppliers s ON s.supplier_id = p.supplier_id
        JOIN employees e ON e.employee_id = p.employee_id
        JOIN purchase_items pi ON pi.purchase_id = p.purchase_id
        JOIN products pr ON pr.product_id = pi.product_id
        GROUP BY p.purchase_id, p.purchase_date, p.total_cost, s.supplier_name, e.full_name
        ORDER BY p.purchase_date DESC, p.purchase_id DESC
    ');

    ob_start();
    ?>
    <?= page_header('Stock intake', 'Supplier Purchases', 'Review purchase orders, supplier activity, staff responsibility, and item-level restocking.') ?>
    <?= table_html(['Purchase', 'Supplier', 'Employee', 'Items', 'Total Cost', 'Date'], $purchases, function (array $purchase): string {
        return '<td>#' . h($purchase['purchase_id']) . '</td>'
            . '<td>' . h($purchase['supplier_name']) . '</td>'
            . '<td>' . h($purchase['employee_name']) . '</td>'
            . '<td>' . h($purchase['items']) . '</td>'
            . '<td class="num">' . money($purchase['total_cost']) . '</td>'
            . '<td>' . h($purchase['purchase_date']) . '</td>';
    }) ?>
    <?php
    return (string) ob_get_clean();
}

function render_reports(PDO $db): string
{
    $stock = rows($db, '
        SELECT p.product_name, c.category_name, s.supplier_name, p.unit_price, p.quantity_in_stock, p.reorder_level
        FROM products p
        JOIN categories c ON c.category_id = p.category_id
        JOIN suppliers s ON s.supplier_id = p.supplier_id
        ORDER BY c.category_name, p.product_name
    ');
    $history = rows($db, '
        SELECT c.full_name AS customer_name, s.sale_id, s.sale_date, s.total_amount, e.full_name AS served_by
        FROM sales s
        JOIN customers c ON c.customer_id = s.customer_id
        JOIN employees e ON e.employee_id = s.employee_id
        ORDER BY c.full_name, s.sale_date
    ');
    $supplierSummary = rows($db, '
        SELECT s.supplier_name, COUNT(p.purchase_id) AS purchase_count, COALESCE(SUM(p.total_cost), 0) AS total_purchased
        FROM suppliers s
        LEFT JOIN purchases p ON p.supplier_id = s.supplier_id
        GROUP BY s.supplier_id, s.supplier_name
        ORDER BY total_purchased DESC
    ');
    $dailySales = rows($db, '
        SELECT DATE(sale_date) AS sale_day, COUNT(*) AS number_of_sales, SUM(total_amount) AS daily_sales_total
        FROM sales
        GROUP BY DATE(sale_date)
        ORDER BY sale_day
    ');
    $lowStock = rows($db, '
        SELECT product_name, quantity_in_stock, reorder_level
        FROM products
        WHERE quantity_in_stock <= reorder_level
        ORDER BY product_name
    ');

    ob_start();
    ?>
    <?= page_header('Management views', 'Reports', 'Live reporting views for stock, sales history, suppliers, daily revenue, and reorder risk.') ?>
    <section class="report-stack">
        <article>
            <h2>Product Stock List</h2>
            <?= table_html(['Product', 'Category', 'Supplier', 'Price', 'Stock', 'Reorder'], $stock, function (array $item): string {
                return '<td>' . h($item['product_name']) . '</td>'
                    . '<td>' . h($item['category_name']) . '</td>'
                    . '<td>' . h($item['supplier_name']) . '</td>'
                    . '<td class="num">' . money($item['unit_price']) . '</td>'
                    . '<td class="num">' . h($item['quantity_in_stock']) . '</td>'
                    . '<td class="num">' . h($item['reorder_level']) . '</td>';
            }) ?>
        </article>
        <article>
            <h2>Customer Sales History</h2>
            <?= table_html(['Customer', 'Sale', 'Date', 'Served By', 'Total'], $history, function (array $sale): string {
                return '<td>' . h($sale['customer_name']) . '</td>'
                    . '<td>#' . h($sale['sale_id']) . '</td>'
                    . '<td>' . h($sale['sale_date']) . '</td>'
                    . '<td>' . h($sale['served_by']) . '</td>'
                    . '<td class="num">' . money($sale['total_amount']) . '</td>';
            }) ?>
        </article>
        <article>
            <h2>Supplier Purchase Summary</h2>
            <?= table_html(['Supplier', 'Purchases', 'Total Purchased'], $supplierSummary, function (array $supplier): string {
                return '<td>' . h($supplier['supplier_name']) . '</td>'
                    . '<td class="num">' . h($supplier['purchase_count']) . '</td>'
                    . '<td class="num">' . money($supplier['total_purchased']) . '</td>';
            }) ?>
        </article>
        <article>
            <h2>Daily Sales Totals</h2>
            <?= table_html(['Date', 'Number of Sales', 'Sales Total'], $dailySales, function (array $day): string {
                return '<td>' . h($day['sale_day']) . '</td>'
                    . '<td class="num">' . h($day['number_of_sales']) . '</td>'
                    . '<td class="num">' . money($day['daily_sales_total']) . '</td>';
            }) ?>
        </article>
        <article>
            <h2>Low-Stock Report</h2>
            <?= table_html(['Product', 'Stock', 'Reorder Level', 'Status'], $lowStock, function (array $item): string {
                return '<td>' . h($item['product_name']) . '</td>'
                    . '<td class="num">' . h($item['quantity_in_stock']) . '</td>'
                    . '<td class="num">' . h($item['reorder_level']) . '</td>'
                    . '<td>' . badge('Reorder', 'warning') . '</td>';
            }) ?>
        </article>
    </section>
    <?php
    return (string) ob_get_clean();
}

function render_setup_error(Throwable $e): string
{
    ob_start();
    ?>
    <section class="setup-error">
        <p class="eyebrow">Database connection needed</p>
        <h1>Import the database to run the demo</h1>
        <p>The PHP site is installed, but it could not connect to the Sunrise Mini Mart database.</p>
        <div class="notice notice-error"><?= h($e->getMessage()) ?></div>
        <ol>
            <li>Start Apache and MySQL in the XAMPP Control Panel.</li>
            <li>Open phpMyAdmin and import <code>setup_database.sql</code>, or run the MySQL command shown in the README.</li>
            <li>Refresh this page.</li>
        </ol>
    </section>
    <?php
    return (string) ob_get_clean();
}

$notice = null;
$content = '';

try {
    $db = pdo($config);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_sale') {
        $notice = handle_record_sale($db);
        $page = 'sales';
    }

    $content = match ($page) {
        'products' => render_products($db),
        'customers' => render_customers($db),
        'sales' => render_sales($db, $notice),
        'purchases' => render_purchases($db),
        'reports' => render_reports($db),
        default => render_dashboard($db),
    };
} catch (Throwable $e) {
    $content = render_setup_error($e);
}

$nav = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => '⌁'],
    'products' => ['label' => 'Products', 'icon' => '▦'],
    'customers' => ['label' => 'Customers', 'icon' => '◉'],
    'sales' => ['label' => 'Sales', 'icon' => '₵'],
    'purchases' => ['label' => 'Purchases', 'icon' => '⇄'],
    'reports' => ['label' => 'Reports', 'icon' => '▣'],
];
$nav = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => 'D'],
    'products' => ['label' => 'Products', 'icon' => 'P'],
    'customers' => ['label' => 'Customers', 'icon' => 'C'],
    'sales' => ['label' => 'Sales', 'icon' => 'S'],
    'purchases' => ['label' => 'Purchases', 'icon' => 'B'],
    'reports' => ['label' => 'Reports', 'icon' => 'R'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sunrise Mini Mart Database Demo</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">SM</span>
            <div>
                <strong>Sunrise Mini Mart</strong>
                <small>Retail Data Console</small>
            </div>
        </div>
        <nav>
            <?php foreach ($nav as $key => $item): ?>
                <a class="<?= $page === $key ? 'active' : '' ?>" href="<?= h(app_url($key)) ?>">
                    <span><?= h($item['icon']) ?></span>
                    <?= h($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-note">
            <span class="status-dot"></span>
            <strong>MySQL connected</strong>
            <span>sunrise_mini_mart_db</span>
        </div>
    </aside>
    <main class="main">
        <?= $content ?>
    </main>
</body>
</html>

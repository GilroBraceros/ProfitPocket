<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/platform.php';
require_once __DIR__ . '/includes/dashboard.php';
$user = current_user();
if ($user) {
    redirect_to(home_path($user));
}
$info = [];
try {
    $info = platform_information(db());
} catch (Throwable $e) {
    error_log('ProfitPocket: public contact settings unavailable.');
}
$page_title = 'Manage smarter. Track better.';
$active_page = 'landing';
require __DIR__ . '/includes/header.php';
?>
<div class="pp-public">
    <a class="visually-hidden-focusable" href="#main-content">Skip to content</a>
    <nav class="navbar navbar-expand-lg pp-public-nav" aria-label="Main navigation">
        <div class="container">
            <a class="pp-wordmark" href="<?= e(app_url()) ?>"><img src="<?= e(app_url('assets/images/logo-icon.jpg')) ?>" width="42" height="42" alt="">ProfitPocket</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#public-navigation" aria-controls="public-navigation" aria-expanded="false" aria-label="Open navigation"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="public-navigation">
                <div class="navbar-nav mx-auto"><?php foreach (['Home' => '#home', 'Features' => '#features', 'How It Works' => '#how-it-works', 'Pricing' => '#pricing', 'About' => '#about'] as $label => $href): ?><a class="nav-link" href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?></div>
                <div class="d-flex gap-2 py-2"><a class="btn btn-outline-secondary" href="<?= e(app_url('auth/login.php')) ?>">Sign In</a><a class="btn btn-primary" href="<?= e(app_url('auth/register.php')) ?>">Get Started <?= dashboard_icon('arrow-up-right-square') ?></a></div>
            </div>
        </div>
    </nav>
    <main id="main-content">
        <section class="pp-hero container" id="home">
            <div>
                <p class="pp-kicker"><span></span> MADE FOR FILIPINO MICRO-BUSINESSES</p>
                <h1>Manage smarter.<br><span>Track better.</span></h1>
                <p class="pp-hero-copy">Take control of your business finances with ProfitPocket. Track sales, manage expenses, monitor customer payments, and understand your business performance in one convenient platform.</p>
                <div class="pp-button-row"><a class="btn btn-primary btn-lg" href="<?= e(app_url('auth/register.php')) ?>">Get Started <?= dashboard_icon('arrow-up-right-square') ?></a><a class="btn btn-outline-secondary btn-lg" href="#features">Explore Features</a></div>
                <p class="pp-fine-print">₱100 per calendar month · Manually verified payments · No automatic charges</p>
            </div>
            <div class="pp-workspace" aria-label="ProfitPocket tools overview">
                <div class="pp-workspace-heading"><img src="<?= e(app_url('assets/images/logo-icon.jpg')) ?>" width="40" height="40" alt="">
                    <div><strong>Your business, in view</strong><span>One organized place for your records</span></div><span class="pp-dot" aria-hidden="true"></span>
                </div>
                <div class="pp-workspace-grid">
                    <div><?= dashboard_icon('receipt') ?><strong>Sales</strong><span>Record every transaction</span></div>
                    <div><?= dashboard_icon('arrow-up-right-square') ?><strong>Expenses</strong><span>Understand your spending</span></div>
                </div>
                <div class="pp-workspace-list"><?php foreach (['people' => ['Customers & balances', 'Keep track of who owes what'], 'box-seam' => ['Products & pricing', 'Keep your catalog organized'], 'bar-chart' => ['Clear financial reports', 'See your business performance']] as $icon => [$title, $copy]): ?><div><?= dashboard_icon($icon) ?><p><strong><?= e($title) ?></strong><span><?= e($copy) ?></span></p><?= dashboard_icon('chevron-down') ?></div><?php endforeach; ?></div>
                <p class="pp-workspace-note"><?= dashboard_icon('journal-text') ?> Less scattered paperwork. More clarity.</p>
            </div>
        </section>
        <section class="pp-section pp-tinted" id="features">
            <div class="container">
                <div class="pp-section-heading">
                    <p class="eyebrow">YOUR EVERYDAY BUSINESS TOOLKIT</p>
                    <h2>Keep the essentials connected.</h2>
                    <p>Practical tools for keeping accurate records, following up on balances and understanding your numbers.</p>
                </div>
                <div class="pp-feature-grid">
                    <?php foreach ([['receipt', 'Sales Management', 'Record sales, discounts and customer payments with a clear transaction history.'], ['arrow-up-right-square', 'Expense Tracking', 'Organize spending, attach receipts and distinguish operating expenses from inventory purchases.'], ['people', 'Customer Management', 'Keep customer details, sales history and outstanding balances together.'], ['box-seam', 'Product Management', 'Maintain your product catalog, selling prices and cost records.'], ['journal-text', 'Payment Management', 'Record collections, partial payments and outstanding credit balances.'], ['bar-chart', 'Financial Reports', 'Review sales, expenses and estimated profit for the period you choose.'], ['grid-1x2', 'Business Dashboard', 'See your financial overview and recent business activity in one place.']] as [$icon, $title, $copy]): ?>
                        <article class="pp-feature"><span class="pp-feature-icon"><?= dashboard_icon($icon) ?></span>
                            <h3><?= e($title) ?></h3>
                            <p><?= e($copy) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <section class="pp-section container" id="how-it-works">
            <div class="pp-section-heading">
                <p class="eyebrow">A CLEAR PATH TO GET STARTED</p>
                <h2>From sign-up to your workspace.</h2>
            </div>
            <ol class="pp-steps">
                <?php foreach ([['Create Your Account', 'Register for a ProfitPocket Business Owner account.'], ['Activate Your Subscription', 'Manually pay ₱100 and submit your proof of payment.'], ['Wait for Verification', 'A System Administrator checks your payment against the receiving account.'], ['Start Managing Your Business', 'Once approved, open your dashboard and business tools.']] as $i => [$title, $copy]): ?><li><span><?= $i + 1 ?></span>
                        <h3><?= e($title) ?></h3>
                        <p><?= e($copy) ?></p>
                    </li><?php endforeach; ?>
            </ol>
        </section>
        <section class="pp-section pp-pricing-section" id="pricing">
            <div class="container pp-pricing-grid">
                <div>
                    <p class="eyebrow">ONE SIMPLE MONTHLY PLAN</p>
                    <h2>More clarity.<br>One practical price.</h2>
                    <p>Keep your business records connected without complicated plans or automatic recurring charges.</p>
                    <p class="pp-fine-print">Subscription activation is subject to payment verification by the ProfitPocket administration team.</p>
                </div>
                <article class="pp-price-card">
                    <p class="pp-kicker">PROFITPOCKET MONTHLY PLAN</p>
                    <h3>₱100 <span>/ month</span></h3>
                    <p>One calendar month of access after approval.</p>
                    <ul><?php foreach (['Business dashboard', 'Sales and expense tracking', 'Customer and product management', 'Payment monitoring', 'Financial reporting', 'Secure account access'] as $feature): ?><li><span aria-hidden="true">✓</span><?= e($feature) ?></li><?php endforeach; ?></ul><a class="btn btn-primary w-100" href="<?= e(app_url('auth/register.php')) ?>">Subscribe for ₱100/month</a>
                    <p class="pp-fine-print">Manual payment. Administrator verified. No automatic charge.</p>
                </article>
            </div>
        </section>
        <section class="pp-section container" id="about">
            <div class="pp-about">
                <p class="eyebrow">BUILT AROUND YOUR BUSINESS</p>
                <h2>A clearer picture of the work you put in.</h2>
                <p>ProfitPocket brings everyday financial records together for micro-entrepreneurs in the Philippines. From your first sale to the month's expense report, it helps you keep organized records and make informed business decisions.</p>
            </div>
        </section>
        <section class="pp-section container pt-0" id="faq">
            <div class="pp-section-heading">
                <p class="eyebrow">A FEW THINGS TO KNOW</p>
                <h2>Frequently asked questions</h2>
            </div>
            <div class="pp-faq">
                <?php foreach (['What is ProfitPocket?' => 'ProfitPocket is a financial management application for Filipino micro-business owners, with connected sales, expenses, customers, products, payments and reports.', 'How much does ProfitPocket cost?' => 'The monthly plan is ₱100 for one calendar month. You pay manually; ProfitPocket does not automatically charge your card or wallet.', 'How do I activate my account?' => 'Register, choose a configured payment method and submit proof without signing in between steps. An administrator must verify and approve your payment before business tools open.', 'How long does payment verification take?' => 'Verification depends on administrator review. Check your subscription page for the current decision or contact support for an update.', 'Can I use ProfitPocket before approval?' => 'You can sign in, view subscription information, manage your password and contact support. Paid business tools become available after approval.', 'What happens when my subscription expires?' => 'Paid features are restricted until renewal is approved. Your existing business records are preserved.', 'How do I renew my subscription?' => 'Open Subscription, manually pay ₱100 using the current instructions and submit new proof. Early renewal extends your existing expiry; an expired subscription restarts when approval succeeds.'] as $question => $answer): ?><details>
                        <summary><?= e($question) ?></summary>
                        <p><?= e($answer) ?></p>
                    </details><?php endforeach; ?>
            </div>
        </section>
        <section class="container pp-final-cta">
            <div>
                <h2>Your next chapter starts with clearer records.</h2>
                <p>Create your account and take the first step.</p>
            </div><a class="btn btn-light" href="<?= e(app_url('auth/register.php')) ?>">Get Started</a>
        </section>
    </main>
    <footer class="container pp-public-footer">
        <div><a class="pp-wordmark" href="<?= e(app_url()) ?>">ProfitPocket</a>
            <p>Manage smarter. Track better.</p><?php if (!empty($info['support_email'])): ?><a href="mailto:<?= e($info['support_email']) ?>"><?= e($info['support_email']) ?></a><?php endif; ?><?php if (!empty($info['support_phone'])): ?><p><?= e($info['support_phone']) ?></p><?php endif; ?>
        </div>
        <nav aria-label="Footer navigation"><a href="#features">Features</a><a href="#pricing">Pricing</a><a href="#about">About</a><a href="<?= e(app_url('auth/login.php')) ?>">Sign In</a></nav>
        <p>© <?= e(date('Y')) ?> ProfitPocket. All rights reserved.</p>
    </footer>
</div>
<?php $hide_default_footer = true;
require __DIR__ . '/includes/footer.php'; ?>

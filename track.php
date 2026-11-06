<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$ticket = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $code = strtoupper(trim((string)($_POST['ticket_code'] ?? '')));
    if (preg_match('/^TK-(\d+)$/', $code, $legacyMatch)) {
        $code = ltrim($legacyMatch[1], '0');
        $code = $code !== '' ? $code : '0';
    }
    if (!preg_match('/^\d{1,20}$/', $code)) {
        $error = 'Vui lòng nhập mã ticket dạng số.';
    } else {
        $pdo = db();
        try {
            $pdo->exec("UPDATE tickets SET resolution_due_at = TIMESTAMPADD(MINUTE, CASE priority WHEN 'high' THEN 120 WHEN 'medium' THEN 240 ELSE 360 END, created_at) WHERE status IN ('new', 'in_progress')");
        } catch (Throwable) {}

        $stmt = $pdo->prepare('SELECT id, ticket_code, status, title, description, type, priority, response_due_at, resolution_due_at, created_at, updated_at, admin_note FROM tickets WHERE ticket_code = ? LIMIT 1');
        $stmt->execute([$code]);
        $ticket = $stmt->fetch() ?: null;
        if (!$ticket) $error = 'Không tìm thấy ticket với thông tin đã nhập.';
    }
}

$statusSteps = ['new', 'in_progress', 'completed'];
$statusIndex = array_search($ticket['status'] ?? '', $statusSteps, true);
$statusIndex = $statusIndex === false ? 0 : $statusIndex;
$statusIsRejected = ($ticket['status'] ?? '') === 'rejected';

page_header('Tra cứu ticket');
?>
<section class="track-search-card">
    <div class="track-search-copy">
        <span class="track-eyebrow">EVERLAND GROUP · HỖ TRỢ NỘI BỘ</span>
        <h2>Tra cứu yêu cầu của bạn</h2>
        <p>Nhập mã ticket để xem trạng thái và tiến độ xử lý mới nhất.</p>
    </div>
    <form method="post" class="track-search-form">
        <?= csrf_field() ?>
        <label for="ticket-code-input">Mã ticket</label>
        <div class="track-search-controls">
            <div class="track-code-input">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3.75h8l4 4V20a1 1 0 0 1-1 1H7a2 2 0 0 1-2-2V5.75a2 2 0 0 1 2-2Z"/><path d="M14 4v5h5M8.5 13h7M8.5 16.5h5"/></svg>
                <input id="ticket-code-input" name="ticket_code" required inputmode="numeric" pattern="[0-9]+" placeholder="Nhập mã, ví dụ 123" value="<?= e($_POST['ticket_code'] ?? '') ?>">
            </div>
            <button type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg>Tra cứu</button>
        </div>
        <small>Mã ticket được cung cấp sau khi gửi yêu cầu.</small>
    </form>
</section>
<?php if ($error !== ''): ?><div class="alert error track-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($ticket): ?>
    <section class="track-result" aria-live="polite">
        <header class="track-result-header">
            <div>
                <span class="track-eyebrow">THÔNG TIN YÊU CẦU</span>
                <h2><?= e($ticket['title']) ?></h2>
                <p>Mã ticket <strong>#<?= e($ticket['ticket_code']) ?></strong></p>
            </div>
            <span class="track-status status-<?= e($ticket['status']) ?>"><?= e(ticket_status_label($ticket['status'])) ?></span>
        </header>
        <div class="track-progress <?= $statusIsRejected ? 'is-rejected' : '' ?>" aria-label="Tiến trình xử lý">
            <?php foreach ($statusSteps as $index => $step): ?>
                <?php $stepComplete = !$statusIsRejected && $index <= $statusIndex; ?>
                <div class="track-progress-step <?= $stepComplete ? 'is-complete' : '' ?> <?= !$statusIsRejected && $index === $statusIndex ? 'is-current' : '' ?>">
                    <span class="track-step-marker"><?= $stepComplete ? '✓' : (string)($index + 1) ?></span>
                    <span><?= e(ticket_status_label($step)) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ($statusIsRejected): ?>
                <div class="track-progress-step is-rejected-step is-current">
                    <span class="track-step-marker">!</span>
                    <span><?= e(ticket_status_label('rejected')) ?></span>
                </div>
            <?php endif; ?>
        </div>
        <div class="track-details-grid">
            <div class="track-detail-item">
                <span>Loại yêu cầu</span>
                <strong><?= e(ticket_type_label($ticket['type'])) ?></strong>
            </div>
            <div class="track-detail-item">
                <span>Mức độ ưu tiên</span>
                <strong class="track-priority priority-<?= e($ticket['priority']) ?>"><?= e(ticket_priority_label($ticket['priority'])) ?></strong>
            </div>
            <div class="track-detail-item">
                <span>Ngày gửi</span>
                <strong><?= e($ticket['created_at']) ?></strong>
            </div>
            <div class="track-detail-item">
                <span>Hạn phản hồi</span>
                <strong><?= e($ticket['response_due_at'] ?? 'Chưa thiết lập') ?></strong>
            </div>
            <div class="track-detail-item">
                <span>Hạn xử lý</span>
                <strong><?= e($ticket['resolution_due_at'] ?? 'Chưa thiết lập') ?></strong>
            </div>
            <div class="track-detail-item">
                <span>Thời hạn cam kết (SLA)</span>
                <strong><?= ticket_sla_hours($ticket['priority']) ?> tiếng</strong>
            </div>
        </div>

        <div class="track-description">
            <div class="track-description-header">
                <span class="track-description-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
                </span>
                <strong>Mô tả chi tiết</strong>
            </div>
            <div class="track-description-content preserve-lines"><?= e($ticket['description'] !== '' ? $ticket['description'] : 'Không có mô tả chi tiết.') ?></div>
        </div>

        <?php if (!empty($ticket['admin_note'])): ?>
            <div class="track-note">
                <span class="track-note-icon" aria-hidden="true">i</span>
                <div><strong>Phản hồi từ bộ phận hỗ trợ</strong><p class="preserve-lines"><?= e($ticket['admin_note']) ?></p></div>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php page_footer(); ?>

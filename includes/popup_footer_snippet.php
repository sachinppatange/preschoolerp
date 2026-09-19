<?php
$popupConfig = [];
if (!empty($settings['popup']) && is_array($settings['popup'])) {
    $popupConfig = $settings['popup'];
}

$popupEnabled = !empty($popupConfig['enabled']);
$popupImage = !empty($popupConfig['image'])
    ? (function_exists('resolve_image_url') ? resolve_image_url((string) $popupConfig['image']) : (string) $popupConfig['image'])
    : '';
$popupTitle = trim((string) ($popupConfig['title'] ?? ''));
$popupText = trim((string) ($popupConfig['text'] ?? ''));
$popupShowOnce = !empty($popupConfig['show_once']);
?>
<?php if ($popupEnabled && ($popupImage !== '' || $popupTitle !== '' || $popupText !== '')): ?>
<div class="modal fade" id="sitePopupModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" id="sitePopupContent" style="overflow:hidden;border-radius:12px;">
      <div class="modal-body p-0">
        <?php if ($popupImage): ?>
          <img src="<?php echo e($popupImage); ?>" alt="<?php echo e($popupTitle); ?>" style="width:100%;height:auto;display:block;">
        <?php endif; ?>
        <div class="p-3">
          <?php if ($popupTitle !== ''): ?><h5 class="mb-2"><?php echo e($popupTitle); ?></h5><?php endif; ?>
          <?php if ($popupText !== ''): ?><div class="small text-muted"><?php echo nl2br(e($popupText)); ?></div><?php endif; ?>
        </div>
      </div>
      <button type="button" class="btn-close position-absolute" data-bs-dismiss="modal" aria-label="Close" style="right:10px;top:10px;"></button>
    </div>
  </div>
</div>

<script>
(function(){
  // Only show if not dismissed (if show_once)
  var showOnce = <?php echo $popupShowOnce ? 'true' : 'false'; ?>;
  var key = 'sitePopupDismissed_v1'; // change suffix if content changes
  if (showOnce && localStorage.getItem(key)) {
    return; // don't show
  }

  // Wait for DOM + Bootstrap ready
  document.addEventListener('DOMContentLoaded', function(){
    var popupEl = document.getElementById('sitePopupModal');
    if (!popupEl) return;
    // Use Bootstrap Modal API
    var bsModal = null;
    try {
      bsModal = new bootstrap.Modal(popupEl, {backdrop: true, keyboard: true});
      bsModal.show();
    } catch(e) {
      // fallback: make visible manually
      popupEl.style.display = 'block';
    }

    // Close when clicking anywhere outside modal-content (explicit requirement)
    document.addEventListener('click', function handler(evt) {
      var content = document.getElementById('sitePopupContent');
      if (!content) return;
      if (!content.contains(evt.target)) {
        // click was outside content -> hide modal
        try { if (bsModal) bsModal.hide(); else popupEl.style.display = 'none'; } catch(e){}
        // if show_once requested, save flag
        if (showOnce) try { localStorage.setItem(key, '1'); } catch(e){}
        // remove handler to avoid repeated triggers
        document.removeEventListener('click', handler, true);
      }
    }, true);

    // Also mark dismissed when using close button or modal hide
    popupEl.addEventListener('hidden.bs.modal', function(){
      if (showOnce) try { localStorage.setItem(key, '1'); } catch(e){}
    });
  });
})();
</script>
<?php endif; ?>
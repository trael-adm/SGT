        </main><!-- /.app-content -->
    </div><!-- /.app-main -->
</div><!-- /.app-wrapper -->

<div class="mobile-overlay" id="mobile-overlay"></div>
<div id="alert-container"></div>

<?php $appJsVer = @filemtime(__DIR__ . '/../assets/js/app.js') ?: (defined('APP_VERSION') ? APP_VERSION : '1'); ?>
<?php // Base da aplicação para o JS (local = .../gft, produção = raiz). Evita caminho fixo. ?>
<script>window.__APP_BASE = <?= json_encode(defined('APP_URL') ? APP_URL : '') ?>;</script>
<script src="<?= htmlspecialchars(defined('APP_URL') ? APP_URL : '') ?>/assets/js/app.js?v=<?= htmlspecialchars((string) $appJsVer) ?>"></script>
</body>
</html>

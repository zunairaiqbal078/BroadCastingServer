
        </div><!-- /page-content -->

        <footer style="padding:8px 24px;text-align:center;font-size:12px;color:var(--text-secondary);border-top:1px solid var(--border)">
            CastPanel &middot; Audio Streaming Platform
        </footer>
    </main>
</div>

<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<?php if (!empty($pageScripts)): ?>
    <?php foreach ($pageScripts as $script): ?>
        <script src="<?= $script ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>

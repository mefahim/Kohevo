-- PageForge uninstall: drop plugin-owned tables (tenant data included,
-- consistent with other Kohevo plugin uninstall baselines).
DROP TABLE IF EXISTS `pageforge_compilations`;
DROP TABLE IF EXISTS `pageforge_revisions`;
DROP TABLE IF EXISTS `pageforge_pages`;

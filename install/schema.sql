-- ============================================================
-- CastPanel v2.4 — Esquema completo para instalaciones nuevas
-- 29 tablas + datos iniciales (admin, paquetes, branding, config, email_templates)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. USUARIOS
-- ============================================================
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `nombre` varchar(128) DEFAULT NULL,
  `rol` enum('admin','reseller','cliente') NOT NULL DEFAULT 'cliente',
  `paquete_reseller_id` int(10) unsigned DEFAULT NULL,
  `reseller_id` int(10) unsigned DEFAULT NULL,
  `max_estaciones` int(10) unsigned NOT NULL DEFAULT 0,
  `max_clientes` int(10) unsigned NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `empresa` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_rol` (`rol`),
  KEY `idx_reseller` (`reseller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. PAQUETES
-- ============================================================
CREATE TABLE IF NOT EXISTS `paquetes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(128) NOT NULL,
  `server_type` enum('shoutcast_v2','shoutcast_v25','shoutcast_v1','icecast') NOT NULL DEFAULT 'shoutcast_v2',
  `allow_system_change` tinyint(1) NOT NULL DEFAULT 1,
  -- Columns the panel also adds at runtime in admin/paquetes.php. Without them
  -- a fresh install fails on the client pages until Paquetes is opened once.
  `default_hostname` varchar(255) NOT NULL DEFAULT '',
  `social_stream_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `mount_points_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `max_storage_mb` int(10) unsigned NOT NULL DEFAULT 5500,
  `max_bitrate` int(10) unsigned NOT NULL DEFAULT 320,
  `max_oyentes` int(10) unsigned NOT NULL DEFAULT 500,
  `autodj_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `max_dj` int(10) unsigned NOT NULL DEFAULT 5,
  `anuncios_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `ssl_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `descripcion` varchar(255) DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL DEFAULT 0.00,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. PAQUETES RESELLER
-- ============================================================
CREATE TABLE IF NOT EXISTS `paquetes_reseller` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `max_clientes` int(11) NOT NULL DEFAULT 10,
  `max_estaciones` int(11) NOT NULL DEFAULT 20,
  `max_storage_mb` int(11) NOT NULL DEFAULT 5000,
  `max_bitrate` int(11) NOT NULL DEFAULT 320,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 4. ESTACIONES
-- ============================================================
CREATE TABLE IF NOT EXISTS `estaciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(10) unsigned NOT NULL,
  `paquete_id` int(10) unsigned DEFAULT NULL,
  `nombre` varchar(128) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `url_web` varchar(255) DEFAULT NULL,
  `titulo_radio` varchar(128) DEFAULT NULL,
  `genero` varchar(64) DEFAULT NULL,
  `server_type` enum('shoutcast_v2','shoutcast_v25','shoutcast_v1','icecast') NOT NULL DEFAULT 'shoutcast_v2',
  `icecast_mount` varchar(128) NOT NULL,
  `icecast_port` int(10) unsigned NOT NULL DEFAULT 8000,
  `icecast_password` varchar(128) NOT NULL,
  `shoutcast_sid` int(10) unsigned NOT NULL DEFAULT 1,
  `codec` enum('mp3','aac','ogg') NOT NULL DEFAULT 'mp3',
  `bitrate` int(10) unsigned NOT NULL DEFAULT 128,
  `samplerate` int(10) unsigned NOT NULL DEFAULT 44100,
  `puerto_stream` int(10) unsigned DEFAULT NULL,
  `puerto_telnet` int(10) unsigned DEFAULT NULL,
  `servicio_estado` enum('stopped','running','error') NOT NULL DEFAULT 'stopped',
  `pid_liquidsoap` int(10) unsigned DEFAULT NULL,
  `channels` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `autodj_estado` enum('offline','online','starting','stopping') NOT NULL DEFAULT 'offline',
  `autodj_playlist_activa` int(10) unsigned DEFAULT NULL,
  `modo_reproduccion` enum('normal','aleatorio','secuencial') NOT NULL DEFAULT 'normal',
  `crossfade_seconds` int(10) unsigned NOT NULL DEFAULT 0,
  `mostrar_titulos` tinyint(1) NOT NULL DEFAULT 1,
  `grabar_dj` tinyint(1) NOT NULL DEFAULT 0,
  `calidad_grabacion` int(10) unsigned NOT NULL DEFAULT 320,
  `buffer_dj_delay` int(10) unsigned NOT NULL DEFAULT 15,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `paquete_id` (`paquete_id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_slug` (`slug`),
  CONSTRAINT `estaciones_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `estaciones_ibfk_2` FOREIGN KEY (`paquete_id`) REFERENCES `paquetes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. PLAYLISTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `playlists` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `nombre` varchar(128) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `total_canciones` int(10) unsigned NOT NULL DEFAULT 0,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_estacion_slug` (`estacion_id`,`slug`),
  CONSTRAINT `playlists_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. CANCIONES
-- ============================================================
CREATE TABLE IF NOT EXISTS `canciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `playlist_id` int(10) unsigned DEFAULT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `nombre_original` varchar(255) NOT NULL,
  `titulo` varchar(255) DEFAULT NULL,
  `artista` varchar(255) DEFAULT NULL,
  `album` varchar(255) DEFAULT NULL,
  `duracion_segundos` int(10) unsigned DEFAULT NULL,
  `tamano_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `ruta_archivo` varchar(512) NOT NULL,
  `caratula` varchar(512) DEFAULT NULL,
  `orden` int(10) unsigned NOT NULL DEFAULT 0,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_estacion` (`estacion_id`),
  KEY `idx_playlist` (`playlist_id`),
  CONSTRAINT `canciones_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `canciones_ibfk_2` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. LISTAS ANUNCIOS
-- ============================================================
CREATE TABLE IF NOT EXISTS `listas_anuncios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `nombre` varchar(128) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `total_anuncios` int(10) unsigned NOT NULL DEFAULT 0,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_estacion_slug` (`estacion_id`,`slug`),
  CONSTRAINT `listas_anuncios_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. ANUNCIOS
-- ============================================================
CREATE TABLE IF NOT EXISTS `anuncios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `lista_id` int(10) unsigned NOT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `nombre_original` varchar(255) NOT NULL,
  `tamano_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `duracion_segundos` int(10) unsigned DEFAULT NULL,
  `ruta_archivo` varchar(512) NOT NULL,
  `orden` int(10) unsigned NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estacion_id` (`estacion_id`),
  KEY `idx_lista` (`lista_id`),
  CONSTRAINT `anuncios_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `anuncios_ibfk_2` FOREIGN KEY (`lista_id`) REFERENCES `listas_anuncios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. PROGRAMACION PLAYLISTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `programacion_playlists` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `playlist_id` int(10) unsigned NOT NULL,
  `dia_semana` enum('lunes','martes','miercoles','jueves','viernes','sabado','domingo') NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fin` time NOT NULL,
  `prioridad` int(10) unsigned NOT NULL DEFAULT 0,
  `estado` enum('activa','inactiva') NOT NULL DEFAULT 'activa',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `playlist_id` (`playlist_id`),
  KEY `idx_estacion_dia` (`estacion_id`,`dia_semana`),
  CONSTRAINT `programacion_playlists_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `programacion_playlists_ibfk_2` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. PROGRAMACION ANUNCIOS
-- ============================================================
CREATE TABLE IF NOT EXISTS `programacion_anuncios` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `lista_anuncios_id` int(10) unsigned NOT NULL,
  `dias` varchar(128) NOT NULL DEFAULT 'todos',
  `hora_inicio` time NOT NULL DEFAULT '00:00:00',
  `hora_fin` time NOT NULL DEFAULT '23:59:00',
  `cada_x_canciones` int(10) unsigned NOT NULL DEFAULT 5,
  `anuncios_por_bloque` int(10) unsigned NOT NULL DEFAULT 1,
  `prioridad` int(10) unsigned NOT NULL DEFAULT 0,
  `estado` enum('activa','inactiva') NOT NULL DEFAULT 'activa',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `estacion_id` (`estacion_id`),
  KEY `lista_anuncios_id` (`lista_anuncios_id`),
  CONSTRAINT `programacion_anuncios_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `programacion_anuncios_ibfk_2` FOREIGN KEY (`lista_anuncios_id`) REFERENCES `listas_anuncios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. CUENTAS DJ
-- ============================================================
CREATE TABLE IF NOT EXISTS `cuentas_dj` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `username` varchar(64) NOT NULL,
  `password` varchar(255) NOT NULL,
  `password_plain` varchar(255) DEFAULT NULL,
  `nombre` varchar(128) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `instagram` varchar(100) DEFAULT '',
  `facebook` varchar(255) DEFAULT '',
  `foto` varchar(255) DEFAULT NULL,
  `idioma` varchar(10) DEFAULT 'es',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `perm_transmitir` tinyint(1) NOT NULL DEFAULT 1,
  `perm_cambiar_pass` tinyint(1) NOT NULL DEFAULT 1,
  `perm_subir_foto` tinyint(1) NOT NULL DEFAULT 0,
  `perm_login_panel` tinyint(1) NOT NULL DEFAULT 1,
  `perm_start_stop` tinyint(1) NOT NULL DEFAULT 0,
  `perm_autodj` tinyint(1) NOT NULL DEFAULT 0,
  `perm_jingles` tinyint(1) NOT NULL DEFAULT 0,
  `perm_voice_pro` tinyint(1) NOT NULL DEFAULT 0,
  `perm_saltar` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_estacion_user` (`estacion_id`,`username`),
  CONSTRAINT `cuentas_dj_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. LOG EMISION
-- ============================================================
CREATE TABLE IF NOT EXISTS `log_emision` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `tipo` enum('cancion','anuncio','dj_live') NOT NULL,
  `titulo` varchar(255) DEFAULT NULL,
  `artista` varchar(255) DEFAULT NULL,
  `origen` varchar(128) DEFAULT NULL,
  `regla` varchar(128) DEFAULT NULL,
  `caratula` varchar(512) DEFAULT NULL,
  `estado` enum('al_aire','emitido') NOT NULL DEFAULT 'al_aire',
  `emitido_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_estacion_fecha` (`estacion_id`,`emitido_at`),
  CONSTRAINT `log_emision_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. ESTADISTICAS OYENTES
-- ============================================================
CREATE TABLE IF NOT EXISTS `estadisticas_oyentes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `oyentes` int(10) unsigned NOT NULL DEFAULT 0,
  `pico_oyentes` int(10) unsigned NOT NULL DEFAULT 0,
  `registrado_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_estacion_fecha` (`estacion_id`,`registrado_at`),
  CONSTRAINT `estadisticas_oyentes_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. CONFIGURACION
-- ============================================================
CREATE TABLE IF NOT EXISTS `configuracion` (
  `clave` varchar(64) NOT NULL,
  `valor` text DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. API KEYS
-- ============================================================
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(128) NOT NULL,
  `api_key` varchar(64) NOT NULL,
  `api_secret` varchar(128) NOT NULL,
  `permisos` set('crear','leer','modificar','eliminar','suspender') NOT NULL DEFAULT 'crear,leer,modificar,eliminar,suspender',
  `ip_permitida` varchar(255) DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_uso` datetime DEFAULT NULL,
  `total_requests` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_key` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. LOG API
-- ============================================================
CREATE TABLE IF NOT EXISTS `log_api` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `api_key_id` int(10) unsigned DEFAULT NULL,
  `accion` varchar(64) NOT NULL,
  `parametros` text DEFAULT NULL,
  `resultado` enum('success','error') NOT NULL,
  `mensaje` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `api_key_id` (`api_key_id`),
  KEY `idx_fecha` (`created_at`),
  CONSTRAINT `log_api_ibfk_1` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 17. LOG ENFORCEMENT
-- ============================================================
CREATE TABLE IF NOT EXISTS `log_enforcement` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `tipo` enum('max_oyentes','max_bitrate','suspension','activacion') NOT NULL,
  `detalle` varchar(255) DEFAULT NULL,
  `valor_actual` int(10) unsigned DEFAULT NULL,
  `valor_limite` int(10) unsigned DEFAULT NULL,
  `accion_tomada` varchar(128) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_estacion_fecha` (`estacion_id`,`created_at`),
  CONSTRAINT `log_enforcement_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. BRANDING
-- ============================================================
CREATE TABLE IF NOT EXISTS `branding` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `owner_type` enum('global','reseller') NOT NULL DEFAULT 'global',
  `owner_id` int(10) unsigned DEFAULT NULL,
  `panel_title` varchar(128) DEFAULT NULL,
  `panel_subtitle` varchar(255) DEFAULT NULL,
  `login_title` varchar(128) DEFAULT NULL,
  `logo_login` varchar(512) DEFAULT NULL,
  `logo_sidebar` varchar(512) DEFAULT NULL,
  `logo_favicon` varchar(512) DEFAULT NULL,
  `logo_player` varchar(512) DEFAULT NULL,
  `primary_color` varchar(7) DEFAULT NULL,
  `sidebar_color` varchar(7) DEFAULT NULL,
  `custom_css` text DEFAULT NULL,
  `footer_text` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 19. EMAIL TEMPLATES
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `asunto` varchar(255) NOT NULL,
  `cuerpo_html` text NOT NULL,
  `variables` text DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 20. AUDIO PROCESSOR
-- ============================================================
CREATE TABLE IF NOT EXISTS `audio_processor` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `preset` varchar(32) NOT NULL DEFAULT 'standard',
  `agc_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `agc_target` float NOT NULL DEFAULT -14,
  `agc_gain_min` float NOT NULL DEFAULT -6,
  `agc_gain_max` float NOT NULL DEFAULT 6,
  `comp_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `comp_threshold` float NOT NULL DEFAULT -15,
  `comp_ratio` float NOT NULL DEFAULT 3,
  `comp_attack` float NOT NULL DEFAULT 10,
  `comp_release` float NOT NULL DEFAULT 100,
  `comp_gain` float NOT NULL DEFAULT 3,
  `comp_knee` float NOT NULL DEFAULT 3,
  `eq_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `eq_low_freq` int(11) NOT NULL DEFAULT 200,
  `eq_low_gain` float NOT NULL DEFAULT 0,
  `eq_mid_freq` int(11) NOT NULL DEFAULT 2500,
  `eq_mid_gain` float NOT NULL DEFAULT 0,
  `eq_mid_q` float NOT NULL DEFAULT 1,
  `eq_high_freq` int(11) NOT NULL DEFAULT 8000,
  `eq_high_gain` float NOT NULL DEFAULT 0,
  `limiter_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `limiter_threshold` float NOT NULL DEFAULT -1,
  `limiter_attack` float NOT NULL DEFAULT 0.1,
  `limiter_release` float NOT NULL DEFAULT 10,
  `output_gain` float NOT NULL DEFAULT 0,
  `stereo_enhance` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_estacion` (`estacion_id`),
  CONSTRAINT `fk_proc_estacion` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 21. PLAYER CONFIG
-- ============================================================
CREATE TABLE IF NOT EXISTS `player_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `estacion_id` int(11) NOT NULL,
  `bg_image` varchar(500) DEFAULT NULL,
  `bg_overlay_opacity` decimal(3,2) DEFAULT 0.60,
  `bg_blur` int(11) DEFAULT 0,
  `cover_image` varchar(500) DEFAULT NULL,
  `effect_type` enum('rain','stars','wave','equalizer','snow','lightning','none') DEFAULT 'rain',
  `accent_color` varchar(7) DEFAULT '#6c3ce0',
  `text_color` varchar(7) DEFAULT '#ffffff',
  `social_facebook` varchar(255) DEFAULT NULL,
  `social_instagram` varchar(255) DEFAULT NULL,
  `social_twitter` varchar(255) DEFAULT NULL,
  `social_youtube` varchar(255) DEFAULT NULL,
  `social_website` varchar(255) DEFAULT NULL,
  `show_history` tinyint(1) DEFAULT 1,
  `show_listeners` tinyint(1) DEFAULT 1,
  `show_eq_bars` tinyint(1) DEFAULT 1,
  `custom_css` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_estacion` (`estacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 22. ROTACION CARPETAS
-- ============================================================
CREATE TABLE IF NOT EXISTS `rotacion_carpetas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `nombre` varchar(128) NOT NULL,
  `dias` varchar(128) NOT NULL DEFAULT 'lunes,martes,miercoles,jueves,viernes,sabado,domingo',
  `hora_inicio` time NOT NULL DEFAULT '00:00:00',
  `hora_fin` time NOT NULL DEFAULT '23:59:00',
  `prioridad` int(10) unsigned NOT NULL DEFAULT 0,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_estacion` (`estacion_id`),
  CONSTRAINT `rotacion_carpetas_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 23. ROTACION CARPETAS SECUENCIA
-- ============================================================
CREATE TABLE IF NOT EXISTS `rotacion_carpetas_secuencia` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rotacion_id` int(10) unsigned NOT NULL,
  `playlist_id` int(10) unsigned NOT NULL,
  `cantidad` int(10) unsigned NOT NULL DEFAULT 1,
  `orden` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `playlist_id` (`playlist_id`),
  KEY `idx_rotacion` (`rotacion_id`),
  CONSTRAINT `rotacion_carpetas_secuencia_ibfk_1` FOREIGN KEY (`rotacion_id`) REFERENCES `rotacion_carpetas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rotacion_carpetas_secuencia_ibfk_2` FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 24. SSL CERTIFICATES
-- ============================================================
CREATE TABLE IF NOT EXISTS `ssl_certificates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL,
  `cert_type` enum('letsencrypt','custom','self-signed') DEFAULT 'letsencrypt',
  `status` enum('active','expired','pending','revoked') DEFAULT 'pending',
  `issued_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `cert_path` varchar(500) DEFAULT NULL,
  `key_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_domain` (`domain`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 25. RELAY / REDIRECCIONAMIENTO
-- ============================================================
CREATE TABLE IF NOT EXISTS `relay_redireccionamiento` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `nombre` varchar(128) NOT NULL,
  `url_fuente` varchar(512) NOT NULL,
  `tipo_horario` enum('24x7','semanal','fecha_unica') NOT NULL DEFAULT '24x7',
  `dias` varchar(128) NOT NULL DEFAULT '',
  `fecha_unica` date DEFAULT NULL,
  `hora_inicio` time NOT NULL DEFAULT '00:00:00',
  `hora_fin` time NOT NULL DEFAULT '23:59:00',
  `prioridad` int(10) unsigned NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_estacion` (`estacion_id`),
  CONSTRAINT `relay_redireccionamiento_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 26. RETRANSMISIONES
-- ============================================================
CREATE TABLE IF NOT EXISTS `retransmisiones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `estacion_id` int(10) unsigned NOT NULL,
  `plataforma` varchar(32) NOT NULL DEFAULT 'custom',
  `nombre` varchar(100) NOT NULL,
  `rtmp_url` varchar(512) NOT NULL,
  `stream_key` varchar(512) NOT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `estado` enum('stopped','running','error') NOT NULL DEFAULT 'stopped',
  `pid` int(10) unsigned DEFAULT NULL,
  `ultimo_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_estacion` (`estacion_id`),
  CONSTRAINT `retransmisiones_ibfk_1` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 27. SHARED PLAYLISTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `shared_playlists` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 28. SHARED PLAYLIST ITEMS
-- ============================================================
CREATE TABLE IF NOT EXISTS `shared_playlist_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `playlist_id` int(10) unsigned NOT NULL,
  `filename` varchar(500) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `artist` varchar(255) DEFAULT NULL,
  `duration` int(11) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_playlist_id` (`playlist_id`),
  CONSTRAINT `shared_playlist_items_ibfk_1` FOREIGN KEY (`playlist_id`) REFERENCES `shared_playlists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 29. SHARED PLAYLIST STATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `shared_playlist_stations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `playlist_id` int(10) unsigned NOT NULL,
  `estacion_id` int(10) unsigned NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_playlist_station` (`playlist_id`,`estacion_id`),
  KEY `idx_estacion_id` (`estacion_id`),
  CONSTRAINT `shared_playlist_stations_ibfk_1` FOREIGN KEY (`playlist_id`) REFERENCES `shared_playlists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shared_playlist_stations_ibfk_2` FOREIGN KEY (`estacion_id`) REFERENCES `estaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DATOS INICIALES
-- ============================================================

-- Admin por defecto (usuario: admin, password: admin123)
INSERT INTO `usuarios` (`username`, `password`, `email`, `nombre`, `rol`, `activo`)
VALUES ('admin', '$2y$10$YuvCqKiCsLW6WYP0LnJrqu7UbIzRJswfQuJxTWi.WlKju/znkCrsa', 'admin@castpanel.com', 'Administrador', 'admin', 1);

-- Paquetes por defecto
INSERT INTO `paquetes` (`nombre`, `server_type`, `allow_system_change`, `max_storage_mb`, `max_bitrate`, `max_oyentes`, `autodj_enabled`, `max_dj`, `anuncios_enabled`, `ssl_enabled`, `descripcion`, `precio`)
VALUES
('Basico', 'shoutcast_v2', 1, 5120, 96, 500, 1, 999999999, 1, 1, 'Plan basico de radio', 6.00),
('PRO', 'shoutcast_v2', 1, 5500, 320, 500, 1, 5, 1, 1, 'Plan profesional', 19.99),
('Premium', 'shoutcast_v2', 1, 15000, 320, 1000, 1, 10, 1, 1, 'Plan premium', 39.99);

-- Branding global por defecto
INSERT INTO `branding` (`owner_type`, `owner_id`, `panel_title`, `panel_subtitle`, `login_title`, `primary_color`, `sidebar_color`, `footer_text`)
VALUES ('global', NULL, 'CastPanel', 'Audio Streaming Plataforma', 'Iniciar Sesion', '#1d0359', '#12026e', 'CastPanel');

-- Configuracion del sistema
INSERT INTO `configuracion` (`clave`, `valor`, `descripcion`) VALUES
('admin_email', '', NULL),
('allowed_extensions', 'mp3,ogg,aac,m4a,wav,flac', NULL),
('anuncios_path', '/var/castpanel/anuncios', 'Ruta base para anuncios'),
('api_enabled', '1', 'Habilitar API externa'),
('api_key', '', NULL),
('auto_provision', '1', 'Aprovisionamiento automatico al crear estaciones'),
('auto_suspend', '0', NULL),
('auto_terminate', '0', NULL),
('billing_system', 'none', NULL),
('branding_upload_path', '/var/castpanel/branding', 'Directorio para logos y favicons de branding'),
('default_bitrate', '96', NULL),
('default_codec', 'mp3', NULL),
('default_language', 'es', NULL),
('default_samplerate', '44100', NULL),
('enforce_interval', '60', 'Intervalo en segundos del cron de enforcement'),
('enforce_limits', '1', 'Habilitar enforcement de limites'),
('google_maps_key', '', NULL),
('icecast_admin_pass', 'CastPanel_Admin_2024!', 'Contrasena admin de Icecast'),
('icecast_admin_port', '8000', 'Puerto admin de Icecast'),
('icecast_admin_user', 'admin', 'Usuario admin de Icecast'),
('icecast_config_path', '/etc/icecast2/icecast.xml', 'Ruta al archivo de configuracion de Icecast'),
('icecast_host', '127.0.0.1', 'Host del servidor Icecast'),
('icecast_port', '8000', NULL),
('liquidsoap_config_path', '/etc/liquidsoap/stations', 'Directorio de configs de Liquidsoap'),
('liquidsoap_host', '127.0.0.1', NULL),
('liquidsoap_port', '1234', NULL),
('liquidsoap_telnet_host', '127.0.0.1', 'Host telnet de Liquidsoap'),
('liquidsoap_telnet_port', '1234', 'Puerto telnet de Liquidsoap'),
('logo_url', '/assets/img/logo.png', 'URL del logo'),
('max_upload_size_mb', '100', NULL),
('media_path', '/var/castpanel/media', 'Ruta base para archivos de audio'),
('nombre_plataforma', 'CastPanel', 'Nombre de la plataforma'),
('panel_version', '2.4', NULL),
('public_player', '1', 'Habilitar pagina publica de reproductor'),
('puerto_rango_fin', '9000', 'Puerto final para asignacion automatica'),
('puerto_rango_inicio', '8100', 'Puerto inicial para asignacion automatica'),
('site_name', 'CastPanel', NULL),
('site_url', '', NULL),
('smtp_enabled', '0', NULL),
('smtp_encryption', 'tls', NULL),
('smtp_from', '', NULL),
('smtp_from_name', 'CastPanel', NULL),
('smtp_host', '', NULL),
('smtp_pass', '', NULL),
('smtp_port', '587', NULL),
('smtp_user', '', NULL),
('ssl_domain', '', 'Dominio SSL para streaming'),
('ssl_enabled', '1', NULL),
('ssl_port_admin', '2087', NULL),
('ssl_port_panel', '443', NULL),
('ssl_port_streaming', '8443', NULL),
('telnet_rango_inicio', '10000', 'Puerto telnet inicial para Liquidsoap'),
('version', '2.4.0', 'Version del sistema');

-- Email templates por defecto
INSERT INTO `email_templates` (`slug`, `nombre`, `asunto`, `cuerpo_html`, `variables`, `activo`) VALUES
('welcome', 'Bienvenida', 'Bienvenido a {{panel_name}}', '<h2>Bienvenido a {{panel_name}}</h2><p>Hola {{username}},</p><p>Tu cuenta ha sido creada exitosamente.</p><p>Puedes acceder al panel en: {{panel_url}}</p>', '["panel_name","username","email","panel_url"]', 1),
('station_created', 'Estacion Creada', 'Tu estacion {{station_name}} ha sido creada', '<h2>Estacion Creada</h2><p>Hola {{username}},</p><p>Tu estacion <strong>{{station_name}}</strong> ha sido creada.</p>', '["panel_name","username","station_name","port","server_type"]', 1),
('password_reset', 'Restablecer Contrasena', 'Restablecer tu contrasena - {{panel_name}}', '<h2>Restablecer Contrasena</h2><p>Hola {{username}},</p><p><a href="{{reset_link}}">Restablecer Contrasena</a></p>', '["panel_name","username","reset_link"]', 1),
('suspension', 'Suspension', 'Tu cuenta ha sido suspendida - {{panel_name}}', '<h2>Cuenta Suspendida</h2><p>Hola {{username}},</p><p>Tu cuenta ha sido suspendida.</p>', '["panel_name","username","email"]', 1),
('reactivation', 'Reactivacion', 'Tu cuenta ha sido reactivada - {{panel_name}}', '<h2>Cuenta Reactivada</h2><p>Hola {{username}},</p><p>Tu cuenta ha sido reactivada.</p>', '["panel_name","username","email"]', 1),
('account_station', 'Cuenta y Estacion', 'Tu cuenta y radio han sido creadas - {{panel_name}}', '<h2>Bienvenido a {{panel_name}}</h2><p>Hola {{username}},</p><p>Tu cuenta y estacion <strong>{{station_name}}</strong> han sido creadas.</p><p><strong>Datos de acceso:</strong></p><ul><li>Usuario: {{username}}</li><li>Contrasena: {{password}}</li><li>Puerto: {{port}}</li><li>URL Panel: {{panel_url}}</li></ul>', '["panel_name","username","password","station_name","port","panel_url"]', 1);

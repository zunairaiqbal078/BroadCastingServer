-- LOCAL DEV ONLY test data. Logins: admin / password, demo1 / demo123, demo2 / demo123
UPDATE usuarios SET password='$2y$10$pJTt3HHCWtyntzGxiRP6dud9EIc8mPbDJ5F4x.AjNS.fkGGTXHXre' WHERE username='admin';

-- Package without AutoDJ (live-only), to test Error 1 / Error 2
INSERT INTO paquetes (nombre, server_type, allow_system_change, max_storage_mb, max_bitrate, max_oyentes, autodj_enabled, max_dj, anuncios_enabled, ssl_enabled, descripcion, precio)
VALUES ('Live Only (dev)', 'shoutcast_v2', 1, 1024, 320, 100, 0, 5, 0, 1, 'Dev test: no AutoDJ', 0);

INSERT INTO usuarios (username, password, email, nombre, rol, activo) VALUES
('demo1', '$2y$10$0PyixTTOK8UcFBJSZgodKOVguYVtVSxx0q87gWRoH23WfIca/uLv6', 'demo1@example.test', 'Demo AutoDJ', 'cliente', 1),
('demo2', '$2y$10$0PyixTTOK8UcFBJSZgodKOVguYVtVSxx0q87gWRoH23WfIca/uLv6', 'demo2@example.test', 'Demo Live', 'cliente', 1);

INSERT INTO estaciones (usuario_id, paquete_id, nombre, slug, server_type, icecast_mount, icecast_password, puerto_stream, puerto_telnet)
SELECT u.id, (SELECT id FROM paquetes WHERE nombre='PRO'), 'Demo AutoDJ FM', 'demo-autodj', 'shoutcast_v2', '/demo-autodj', 'srcpass1', 8010, 1240 FROM usuarios u WHERE u.username='demo1';
INSERT INTO estaciones (usuario_id, paquete_id, nombre, slug, server_type, icecast_mount, icecast_password, puerto_stream, puerto_telnet)
SELECT u.id, (SELECT id FROM paquetes WHERE nombre='Live Only (dev)'), 'Demo Live FM', 'demo-live', 'shoutcast_v2', '/demo-live', 'srcpass2', 8020, 1250 FROM usuarios u WHERE u.username='demo2';

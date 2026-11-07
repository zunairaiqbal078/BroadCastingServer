#!/usr/bin/liquidsoap
# ==============================================
# CastPanel - Configuracion automatica
# Estacion: {{STATION_SLUG}}
# ==============================================

settings.server.telnet.set(true)
settings.server.telnet.bind_addr.set("127.0.0.1")
settings.server.telnet.port.set({{TELNET_PORT}})

# Logging
settings.log.stdout.set(true)
settings.log.level.set(3)

# === FUENTES DE AUDIO ===

# Playlist principal (desde M3U generado por el panel)
music = playlist(
    id="music_{{STATION_SLUG}}",
    mode="{{PLAY_MODE}}",
    reload=10,
    reload_mode="watch",
    "{{PLAYLIST_FILE}}"
)

# Anuncios (spots publicitarios)
ads = playlist(
    id="ads_{{STATION_SLUG}}",
    mode="randomize",
    reload=60,
    reload_mode="watch",
    "{{ANUNCIOS_PATH}}/{{STATION_SLUG}}"
)

# Silencio como fallback final
silence = blank(id="silence_{{STATION_SLUG}}")

# === PROCESAMIENTO ===

# Insertar anuncios cada 5 canciones
radio = rotate(
    weights=[5, 1],
    [music, ads]
)

# Crossfade entre pistas
radio = crossfade(
    duration={{CROSSFADE}}.0,
    radio
)

# Entrada DJ en vivo (input.harbor)
live_dj = input.harbor(
    id="live_{{STATION_SLUG}}",
    "/live/{{STATION_SLUG}}",
    port={{HARBOR_PORT}},
    password="{{ICECAST_PASSWORD}}"
)

# DJ en vivo tiene prioridad sobre AutoDJ
radio = fallback(
    id="fallback_{{STATION_SLUG}}",
    track_sensitive=false,
    [live_dj, radio, silence]
)

# Procesador de audio
radio = normalize(radio)

# Webhook de metadata al panel
def on_metadata(m) =
    title = m["title"]
    artist = m["artist"]
    ignore(
        http.post(
            data="station={{STATION_SLUG}}&title=#{title}&artist=#{artist}",
            "{{PANEL_API_URL}}/api/webhook.php"
        )
    )
end
radio.on_metadata(on_metadata)

# {{OUTPUT_BLOCK}}

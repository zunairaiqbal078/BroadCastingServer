<?php
/**
 * CastPanel v2.3 - Reproductor Compacto (Embed/iframe) - Estilo SonicPanel Light
 * Altura: 110px - Para embeber en páginas externas
 * URL: /widgets/player/single/?slug=mi-radio
 *      /widgets/player/single/?p=8002
 */
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';

$db = getDB();

$slug = $_GET['slug'] ?? $_GET['station'] ?? '';
$port = (int)($_GET['p'] ?? 0);

if ($slug) {
    $stmt = $db->prepare("SELECT * FROM estaciones WHERE slug = ? AND activa = 1");
    $stmt->execute([$slug]);
} elseif ($port) {
    $stmt = $db->prepare("SELECT * FROM estaciones WHERE (puerto_stream = ? OR icecast_port = ?) AND activa = 1");
    $stmt->execute([$port, $port]);
} else {
    die('Parámetro slug o p requerido');
}
$estacion = $stmt->fetch();
if (!$estacion) die('Estación no encontrada');

$streamUrl = castpanelStreamPlaybackUrl($estacion);
$fallbackUrl = isShoutcastServerType($estacion['server_type'] ?? '')
    ? preg_replace('#/shoutcast/#', '/live/', $streamUrl)
    : '';
$apiUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? SSL_DOMAIN) . preg_replace('#/widgets/.*$#', '', $_SERVER['SCRIPT_NAME']) . '/api/nowplaying.php?slug=' . urlencode($estacion['slug']);
$radioName = htmlspecialchars($estacion['titulo_radio'] ?: $estacion['nombre']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $radioName ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    html,body{
        height:110px; overflow:hidden;
        font-family:'Open Sans', Arial, sans-serif;
        background:#f5f5f5;
        color:#333;
    }
    .sp{
        display:flex; align-items:center; gap:12px;
        padding:12px 16px;
        height:110px;
        background:#fff;
        border-radius:10px;
        box-shadow:0 2px 10px rgba(0,0,0,0.06);
        border:1px solid #e8e8e8;
    }
    /* Cover */
    .sp-cover{
        width:80px; height:80px;
        border-radius:12px;
        background:#f0f0f0;
        flex-shrink:0;
        display:flex; align-items:center; justify-content:center;
        overflow:hidden;
        box-shadow:0 3px 12px rgba(0,0,0,.08);
        position:relative;
        border:2px solid #e8e8e8;
    }
    .sp-cover img{width:100%;height:100%;object-fit:cover}
    .sp-cover .ph{font-size:30px;opacity:.3}
    /* Glow cuando reproduce */
    .sp-cover.playing{
        border-color:#2196F3;
        box-shadow:0 3px 16px rgba(33,150,243,.2);
    }

    /* Info */
    .sp-info{flex:1;min-width:0;padding-right:4px}
    .sp-station{
        font-size:10px;font-weight:700;text-transform:uppercase;
        letter-spacing:.5px; color:#999;
        margin-bottom:2px;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    }
    .sp-title{
        font-size:14px;font-weight:700;
        color:#333;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        margin-bottom:1px;
        line-height:1.3;
    }
    .sp-artist{
        font-size:11px;color:#999;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        margin-bottom:6px;
    }
    /* Mini EQ + Status */
    .sp-bottom{
        display:flex; align-items:center; gap:6px;
    }
    .mini-eq{
        display:flex;align-items:flex-end;gap:2px;height:14px;
        opacity:0;transition:opacity .3s;
    }
    .mini-eq.on{opacity:1}
    .mini-eq .mb{
        width:3px;border-radius:1.5px;
        background:#2196F3;
        animation:mEq .8s ease-in-out infinite;
    }
    .mini-eq .mb:nth-child(1){height:5px;animation-delay:0s}
    .mini-eq .mb:nth-child(2){height:10px;animation-delay:.12s}
    .mini-eq .mb:nth-child(3){height:7px;animation-delay:.24s}
    .mini-eq .mb:nth-child(4){height:12px;animation-delay:.08s}
    .mini-eq .mb:nth-child(5){height:4px;animation-delay:.2s}
    @keyframes mEq{0%,100%{transform:scaleY(.35)}50%{transform:scaleY(1)}}

    .sp-status{
        font-size:10px;font-weight:600;
        padding:2px 8px;border-radius:10px;
        display:inline-flex;align-items:center;gap:4px;
    }
    .sp-status.on{background:rgba(76,175,80,.1);color:#4CAF50}
    .sp-status.off{background:rgba(0,0,0,.04);color:#bbb}
    .sp-status .sd{width:5px;height:5px;border-radius:50%}
    .sp-status.on .sd{background:#4CAF50;animation:bk 1.5s infinite}
    .sp-status.off .sd{background:#ccc}
    @keyframes bk{0%,100%{opacity:1}50%{opacity:.3}}

    .sp-listeners{
        font-size:10px;color:#bbb;
        margin-left:auto;
    }

    /* Controles */
    .sp-ctrl{
        display:flex; flex-direction:column; align-items:center; gap:6px;
        flex-shrink:0;
    }
    .sp-play{
        width:48px;height:48px;border-radius:50%;
        background:#fff;
        border:2px solid #2196F3;
        color:#2196F3;
        font-size:18px;cursor:pointer;
        display:flex;align-items:center;justify-content:center;
        transition:all .2s;
        box-shadow:0 2px 8px rgba(33,150,243,.15);
    }
    .sp-play:hover{background:#2196F3;color:#fff}
    .sp-play:active{transform:scale(.92)}
    .sp-play.playing{background:#2196F3;color:#fff}

    .sp-vol{
        display:flex;align-items:center;gap:4px;
    }
    .sp-vol-icon{
        cursor:pointer;
        color:#999;
        background:none;border:none;
        padding:2px;
        display:flex;align-items:center;justify-content:center;
        line-height:0;
    }
    .sp-vol-icon:hover{color:#2196F3}
    .sp-vol-icon svg{display:block}
    .sp-vol-slider{
        width:56px;height:3px;
        -webkit-appearance:none;appearance:none;
        background:#e0e0e0;
        border-radius:3px;outline:none;
    }
    .sp-vol-slider::-webkit-slider-thumb{
        -webkit-appearance:none;
        width:10px;height:10px;border-radius:50%;
        background:#2196F3;cursor:pointer;
    }
    .sp-vol-slider::-moz-range-thumb{
        width:10px;height:10px;border-radius:50%;
        background:#2196F3;border:none;cursor:pointer;
    }
    </style>
</head>
<body>
<div class="sp">
    <!-- Cover -->
    <div class="sp-cover" id="cover">
        <span class="ph"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span>
    </div>

    <!-- Info -->
    <div class="sp-info">
        <div class="sp-station" id="stName"><?= $radioName ?></div>
        <div class="sp-title" id="spTitle">Cargando...</div>
        <div class="sp-artist" id="spArtist">&nbsp;</div>
        <div class="sp-bottom">
            <div class="mini-eq" id="mEq">
                <div class="mb"></div><div class="mb"></div><div class="mb"></div>
                <div class="mb"></div><div class="mb"></div>
            </div>
            <div class="sp-status off" id="spBadge">
                <span class="sd"></span><span id="spSt">---</span>
            </div>
            <div class="sp-listeners" id="spLis"></div>
        </div>
    </div>

    <!-- Controles -->
    <div class="sp-ctrl">
        <button class="sp-play" id="spPlay" onclick="tPlay()"><svg id="spPlayIcon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg></button>
        <div class="sp-vol">
            <button class="sp-vol-icon" id="spVolBtn" onclick="tMute()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg></button>
            <input type="range" class="sp-vol-slider" id="spVol" min="0" max="100" value="80" oninput="sVol(this.value)">
        </div>
    </div>
</div>

<script>
(function(){
    var url="<?= $streamUrl ?>",fb="<?= $fallbackUrl ?>",api="<?= $apiUrl ?>",a=null,p=false,v=80,rt=false;

    function load(){
        fetch(api).then(function(r){return r.json()}).then(function(d){
            if(d.error)return;
            document.getElementById('stName').textContent=d.station.name;
            var b=document.getElementById('spBadge');
            if(d.live.online){b.className='sp-status on';document.getElementById('spSt').textContent='EN VIVO'}
            else{b.className='sp-status off';document.getElementById('spSt').textContent='OFFLINE'}
            if(d.now_playing&&d.now_playing.title){
                document.getElementById('spTitle').textContent=d.now_playing.title;
                document.getElementById('spArtist').textContent=d.now_playing.artist||'';
                if(d.now_playing.cover){document.getElementById('cover').innerHTML='<img src="'+d.now_playing.cover+'" alt="">';}
            }else if(d.live.title){
                document.getElementById('spTitle').textContent=d.live.title;
            }
            if(d.live.listeners!==undefined){
                document.getElementById('spLis').innerHTML='<svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:2px"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'+(d.live.listeners||0);
            }
        }).catch(function(){});
    }

    function setUI(on){
        p=on;
        var btn=document.getElementById('spPlay');
        btn.innerHTML=on?'<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>':'<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
        btn.className=on?'sp-play playing':'sp-play';
        document.getElementById('mEq').className=on?'mini-eq on':'mini-eq';
        var c=document.getElementById('cover');
        if(on)c.classList.add('playing');else c.classList.remove('playing');
    }

    function tryUrl(u,canFb){
        if(a){a.pause();a.src='';a=null;}
        a=new Audio(u);a.volume=v/100;a.crossOrigin='anonymous';
        a.onplaying=function(){rt=false;setUI(true);};
        a.onerror=function(){
            if(canFb&&fb&&!rt){rt=true;tryUrl(fb,false);return;}
            rt=false;setUI(false);a=null;
        };
        setUI(true);
        a.play().catch(function(){
            if(canFb&&fb&&!rt){rt=true;tryUrl(fb,false);return;}
            rt=false;setUI(false);a=null;
        });
    }

    window.tPlay=function(){
        if(p){if(a){a.pause();a.src='';a=null;}setUI(false);}
        else{tryUrl(url,true);}
    };
    window.sVol=function(n){v=n;if(a)a.volume=n/100;};
    window.tMute=function(){
        var s=document.getElementById('spVol');
        var b=document.getElementById('spVolBtn');
        if(v>0){
            window._pv=v;sVol(0);s.value=0;
            b.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';
        } else {
            sVol(window._pv||80);s.value=v;
            b.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>';
        }
    };

    load();
    setInterval(load,12000);
})();
</script>
</body>
</html>

<?php
/**
 * CastPanel v2.2 - Motor de Procesador de Audio
 *
 * Genera la cadena de procesamiento Liquidsoap:
 * AGC → Compresor → EQ 3 bandas → Limitador → Ganancia de salida
 *
 * Presets profesionales inspirados en procesadores de broadcast.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Presets de procesamiento de audio
 * Cada preset define valores optimos para un tipo de contenido
 */
function getProcessorPresets(): array {
    return [
        'off' => [
            'nombre' => 'Desactivado',
            'desc'   => 'Sin procesamiento, audio original',
            'icon'   => '&#x1F507;',
            'values' => ['enabled' => 0],
        ],
        'soft' => [
            'nombre' => 'Suave',
            'desc'   => 'Compresi' . "\xC3\xB3" . 'n ligera, ideal para m' . "\xC3\xBA" . 'sica cl' . "\xC3\xA1" . 'sica y jazz',
            'icon'   => '&#x1F3B6;',
            'values' => [
                'enabled' => 1,
                'agc_enabled' => 1, 'agc_target' => -16.0, 'agc_gain_min' => -4.0, 'agc_gain_max' => 4.0,
                'comp_enabled' => 1, 'comp_threshold' => -20.0, 'comp_ratio' => 2.0, 'comp_attack' => 20.0, 'comp_release' => 200.0, 'comp_gain' => 2.0, 'comp_knee' => 6.0,
                'eq_enabled' => 0, 'eq_low_gain' => 0.0, 'eq_mid_gain' => 0.0, 'eq_high_gain' => 0.0,
                'limiter_enabled' => 1, 'limiter_threshold' => -1.0, 'limiter_attack' => 0.5, 'limiter_release' => 20.0,
                'output_gain' => 0.0, 'stereo_enhance' => 0,
            ],
        ],
        'standard' => [
            'nombre' => 'Est' . "\xC3\xA1" . 'ndar',
            'desc'   => 'Procesamiento balanceado para radio general',
            'icon'   => '&#x1F4FB;',
            'values' => [
                'enabled' => 1,
                'agc_enabled' => 1, 'agc_target' => -14.0, 'agc_gain_min' => -6.0, 'agc_gain_max' => 6.0,
                'comp_enabled' => 1, 'comp_threshold' => -15.0, 'comp_ratio' => 3.0, 'comp_attack' => 10.0, 'comp_release' => 100.0, 'comp_gain' => 3.0, 'comp_knee' => 3.0,
                'eq_enabled' => 1, 'eq_low_gain' => 2.0, 'eq_mid_gain' => 0.5, 'eq_high_gain' => 1.5,
                'limiter_enabled' => 1, 'limiter_threshold' => -0.5, 'limiter_attack' => 0.1, 'limiter_release' => 10.0,
                'output_gain' => 1.0, 'stereo_enhance' => 0,
            ],
        ],
        'powerful' => [
            'nombre' => 'Potente',
            'desc'   => 'M' . "\xC3\xA1" . 'xima fuerza y volumen, sonido de FM comercial',
            'icon'   => '&#x1F525;',
            'values' => [
                'enabled' => 1,
                'agc_enabled' => 1, 'agc_target' => -12.0, 'agc_gain_min' => -8.0, 'agc_gain_max' => 8.0,
                'comp_enabled' => 1, 'comp_threshold' => -12.0, 'comp_ratio' => 5.0, 'comp_attack' => 5.0, 'comp_release' => 60.0, 'comp_gain' => 5.0, 'comp_knee' => 2.0,
                'eq_enabled' => 1, 'eq_low_gain' => 4.0, 'eq_mid_gain' => 1.0, 'eq_high_gain' => 3.0,
                'limiter_enabled' => 1, 'limiter_threshold' => -0.3, 'limiter_attack' => 0.05, 'limiter_release' => 5.0,
                'output_gain' => 3.0, 'stereo_enhance' => 1,
            ],
        ],
        'rock' => [
            'nombre' => 'Rock / Pop',
            'desc'   => 'Compresi' . "\xC3\xB3" . 'n agresiva, medios al frente, punch',
            'icon'   => '&#x1F3B8;',
            'values' => [
                'enabled' => 1,
                'agc_enabled' => 1, 'agc_target' => -13.0, 'agc_gain_min' => -6.0, 'agc_gain_max' => 8.0,
                'comp_enabled' => 1, 'comp_threshold' => -12.0, 'comp_ratio' => 4.5, 'comp_attack' => 8.0, 'comp_release' => 80.0, 'comp_gain' => 4.0, 'comp_knee' => 2.0,
                'eq_enabled' => 1, 'eq_low_gain' => 3.0, 'eq_mid_gain' => 2.0, 'eq_high_gain' => 2.5,
                'limiter_enabled' => 1, 'limiter_threshold' => -0.3, 'limiter_attack' => 0.08, 'limiter_release' => 8.0,
                'output_gain' => 2.0, 'stereo_enhance' => 1,
            ],
        ],
        'talk' => [
            'nombre' => 'Noticias / Voz',
            'desc'   => 'Optimizado para voz hablada, claridad m' . "\xC3\xA1" . 'xima',
            'icon'   => '&#x1F399;',
            'values' => [
                'enabled' => 1,
                'agc_enabled' => 1, 'agc_target' => -14.0, 'agc_gain_min' => -8.0, 'agc_gain_max' => 8.0,
                'comp_enabled' => 1, 'comp_threshold' => -18.0, 'comp_ratio' => 4.0, 'comp_attack' => 5.0, 'comp_release' => 50.0, 'comp_gain' => 4.0, 'comp_knee' => 3.0,
                'eq_enabled' => 1, 'eq_low_gain' => -2.0, 'eq_mid_gain' => 3.0, 'eq_high_gain' => 2.0,
                'limiter_enabled' => 1, 'limiter_threshold' => -0.5, 'limiter_attack' => 0.1, 'limiter_release' => 15.0,
                'output_gain' => 1.0, 'stereo_enhance' => 0,
            ],
        ],
        'custom' => [
            'nombre' => 'Personalizado',
            'desc'   => 'Ajustes manuales del usuario',
            'icon'   => '&#x1F527;',
            'values' => [],
        ],
    ];
}

/**
 * Obtener la configuraci' . "\xC3\xB3" . 'n del procesador para una estaci' . "\xC3\xB3" . 'n
 */
function getProcessorConfig(int $estacionId): array {
    $db = getDB();

    $defaults = [
        'estacion_id' => $estacionId,
        'enabled' => 0,
        'preset' => 'standard',
        'agc_enabled' => 1, 'agc_target' => -14.0, 'agc_gain_min' => -6.0, 'agc_gain_max' => 6.0,
        'comp_enabled' => 1, 'comp_threshold' => -15.0, 'comp_ratio' => 3.0, 'comp_attack' => 10.0, 'comp_release' => 100.0, 'comp_gain' => 3.0, 'comp_knee' => 3.0,
        'eq_enabled' => 0, 'eq_low_freq' => 200, 'eq_low_gain' => 0.0, 'eq_mid_freq' => 2500, 'eq_mid_gain' => 0.0, 'eq_mid_q' => 1.0, 'eq_high_freq' => 8000, 'eq_high_gain' => 0.0,
        'limiter_enabled' => 1, 'limiter_threshold' => -1.0, 'limiter_attack' => 0.1, 'limiter_release' => 10.0,
        'output_gain' => 0.0, 'stereo_enhance' => 0,
    ];

    $stmt = $db->prepare("SELECT * FROM audio_processor WHERE estacion_id = ?");
    $stmt->execute([$estacionId]);
    $row = $stmt->fetch();

    if ($row) {
        return array_merge($defaults, $row);
    }

    return $defaults;
}

/**
 * Guardar la configuraci' . "\xC3\xB3" . 'n del procesador
 */
function saveProcessorConfig(int $estacionId, array $data): bool {
    $db = getDB();

    $fields = [
        'enabled', 'preset',
        'agc_enabled', 'agc_target', 'agc_gain_min', 'agc_gain_max',
        'comp_enabled', 'comp_threshold', 'comp_ratio', 'comp_attack', 'comp_release', 'comp_gain', 'comp_knee',
        'eq_enabled', 'eq_low_freq', 'eq_low_gain', 'eq_mid_freq', 'eq_mid_gain', 'eq_mid_q', 'eq_high_freq', 'eq_high_gain',
        'limiter_enabled', 'limiter_threshold', 'limiter_attack', 'limiter_release',
        'output_gain', 'stereo_enhance',
    ];

    // Filtrar solo campos validos
    $clean = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $data)) {
            $clean[$f] = $data[$f];
        }
    }

    if (empty($clean)) return false;

    // Verificar si ya existe
    $stmt = $db->prepare("SELECT id FROM audio_processor WHERE estacion_id = ?");
    $stmt->execute([$estacionId]);

    if ($stmt->fetch()) {
        // UPDATE
        $sets = [];
        $params = [];
        foreach ($clean as $k => $v) {
            $sets[] = "`$k` = ?";
            $params[] = $v;
        }
        $params[] = $estacionId;
        $sql = "UPDATE audio_processor SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE estacion_id = ?";
        $db->prepare($sql)->execute($params);
    } else {
        // INSERT
        $clean['estacion_id'] = $estacionId;
        $cols = array_map(fn($k) => "`$k`", array_keys($clean));
        $placeholders = array_fill(0, count($clean), '?');
        $sql = "INSERT INTO audio_processor (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $db->prepare($sql)->execute(array_values($clean));
    }

    return true;
}

/**
 * Aplicar un preset al procesador
 */
function applyProcessorPreset(int $estacionId, string $presetKey): bool {
    $presets = getProcessorPresets();
    if (!isset($presets[$presetKey])) return false;

    $preset = $presets[$presetKey];
    $data = $preset['values'];
    $data['preset'] = $presetKey;

    if ($presetKey === 'off') {
        $data['enabled'] = 0;
    }

    return saveProcessorConfig($estacionId, $data);
}

/**
 * Formatear un numero PHP como float de Liquidsoap (siempre con punto decimal).
 * Liquidsoap (OCaml) distingue int de float: 4 != 4. y causa error de tipo.
 * Esta funcion garantiza que el valor siempre tenga punto decimal.
 */
function liqFloat(float $val): string {
    $s = (string)$val;
    // Si no tiene punto decimal ni notacion cientifica, agregar punto
    if (strpos($s, '.') === false && stripos($s, 'e') === false) {
        $s .= '.';
    }
    return $s;
}

/**
 * Generar el bloque Liquidsoap de procesamiento de audio
 *
 * @param array $proc  Configuracion del procesador (de getProcessorConfig)
 * @return string  Codigo Liquidsoap
 */
function generateProcessorLiquidsoap(array $proc): string {
    if (empty($proc['enabled'])) {
        return "# Procesador de audio: DESACTIVADO\nradio = normalize(radio)\n";
    }

    $liq = "# === PROCESADOR DE AUDIO (Preset: {$proc['preset']}) ===\n\n";

    // 1. AGC / Normalizacion
    if (!empty($proc['agc_enabled'])) {
        $gainMin = (float)$proc['agc_gain_min'];
        $gainMax = (float)$proc['agc_gain_max'];
        $liq .= "# AGC - Control Automatico de Ganancia\n";
        $liq .= "radio = normalize(\n";
        $liq .= "    gain_min=" . liqFloat($gainMin) . ",\n";
        $liq .= "    gain_max=" . liqFloat($gainMax) . ",\n";
        $liq .= "    radio\n";
        $liq .= ")\n\n";
    }

    // 2. Compresor
    if (!empty($proc['comp_enabled'])) {
        $threshold = (float)$proc['comp_threshold'];
        $ratio = (float)$proc['comp_ratio'];
        $attack = (float)$proc['comp_attack'];
        $release = (float)$proc['comp_release'];
        $gain = (float)$proc['comp_gain'];
        $knee = (float)$proc['comp_knee'];
        $liq .= "# Compresor de Dinamica\n";
        $liq .= "radio = compress(\n";
        $liq .= "    threshold=" . liqFloat($threshold) . ",\n";
        $liq .= "    ratio=" . liqFloat($ratio) . ",\n";
        $liq .= "    attack=" . liqFloat($attack) . ",\n";
        $liq .= "    release=" . liqFloat($release) . ",\n";
        $liq .= "    gain=" . liqFloat($gain) . ",\n";
        $liq .= "    knee=" . liqFloat($knee) . ",\n";
        $liq .= "    radio\n";
        $liq .= ")\n\n";
    }

    // 3. Ecualizador 3 bandas
    if (!empty($proc['eq_enabled'])) {
        $lowFreq = (float)($proc['eq_low_freq'] ?? 200);
        $lowGain = (float)$proc['eq_low_gain'];
        $midFreq = (float)($proc['eq_mid_freq'] ?? 2500);
        $midGain = (float)$proc['eq_mid_gain'];
        $midQ = (float)($proc['eq_mid_q'] ?? 1.0);
        $highFreq = (float)($proc['eq_high_freq'] ?? 8000);
        $highGain = (float)$proc['eq_high_gain'];

        $liq .= "# Ecualizador 3 Bandas\n";

        if ($lowGain != 0.0) {
            $liq .= "radio = filter.iir.eq.low_shelf(frequency=" . liqFloat($lowFreq) . ", q=1., gain=" . liqFloat($lowGain) . ", radio)\n";
        }
        if ($midGain != 0.0) {
            $liq .= "radio = filter.iir.eq.peak(frequency=" . liqFloat($midFreq) . ", q=" . liqFloat($midQ) . ", gain=" . liqFloat($midGain) . ", radio)\n";
        }
        if ($highGain != 0.0) {
            $liq .= "radio = filter.iir.eq.high_shelf(frequency=" . liqFloat($highFreq) . ", q=1., gain=" . liqFloat($highGain) . ", radio)\n";
        }
        $liq .= "\n";
    }

    // 4. Limitador (compresor con ratio muy alto)
    if (!empty($proc['limiter_enabled'])) {
        $limThreshold = (float)$proc['limiter_threshold'];
        $limAttack = (float)$proc['limiter_attack'];
        $limRelease = (float)$proc['limiter_release'];
        $liq .= "# Limitador de Picos\n";
        $liq .= "radio = compress(\n";
        $liq .= "    threshold=" . liqFloat($limThreshold) . ",\n";
        $liq .= "    ratio=20.,\n";
        $liq .= "    attack=" . liqFloat($limAttack) . ",\n";
        $liq .= "    release=" . liqFloat($limRelease) . ",\n";
        $liq .= "    gain=0.,\n";
        $liq .= "    radio\n";
        $liq .= ")\n\n";
    }

    // 5. Ganancia de salida
    $outputGain = (float)($proc['output_gain'] ?? 0.0);
    if ($outputGain != 0.0) {
        // Convertir dB a factor lineal
        $factor = round(pow(10, $outputGain / 20.0), 4);
        $liq .= "# Ganancia de salida: {$outputGain} dB\n";
        $liq .= "radio = amplify(" . liqFloat($factor) . ", radio)\n\n";
    }

    return $liq;
}

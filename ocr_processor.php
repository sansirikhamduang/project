<?php
/**
 * OCR Processor - Extract license plate from image using Tesseract
 * Uses segmented OCR (head/mid/tail) + top-band extraction + fuzzy matching
 */

const OCR_ENGINE_VERSION = 'ocr-2026-03-18-04';

if (!defined('PARKING_OCR_API_KEY')) {
    $connectFile = __DIR__ . '/connect.php';
    if (file_exists($connectFile)) {
        require_once $connectFile;
    }
}







function extractPlateFromImage($imagePath) {
    // Check if file exists
    if (!file_exists($imagePath)) {
        return ['success' => false, 'error' => 'ไม่พบไฟล์รูป'];
    }

    // Check if Tesseract is installed
    $tesseractPath = findTesseractPath();
    if (!$tesseractPath) {
        return ['success' => false, 'error' => 'Tesseract ยังไม่ได้ติดตั้ง หรือไม่พบในระบบ'];
    }

    // Pre-pass: read top text band directly (works well for faint/low-contrast top lines).
    $topBand = extractPlateFromTopBand($tesseractPath, $imagePath);
    if (!empty($topBand['plate'])) {
        $fixed = postProcessPlate($topBand['plate']);
        return ['success' => true, 'plate' => strtoupper($fixed), 'raw_text' => $topBand['raw_text']];
    }

    // Step 3: Segmented OCR (more accurate for Thai plates)
    $segmented = extractPlateBySegments($tesseractPath, $imagePath);
    if (!empty($segmented['plate'])) {
        $fixed = postProcessPlate($segmented['plate']);
        return ['success' => true, 'plate' => strtoupper($fixed), 'raw_text' => $segmented['raw_text']];
    }

    $ocrSources = [$imagePath];
    $tempImages = preparePlateImageVariants($imagePath);
    foreach ($tempImages as $tmp) {
        $ocrSources[] = $tmp;
    }

    $optionsList = [
        "-l tha+eng --oem 1 --psm 7 -c preserve_interword_spaces=1",
        "-l tha+eng --oem 1 --psm 7 -c tessedit_char_whitelist=0123456789กขคฆงจฉชซฌญฎฏฐฑฒณดตถทธนบปผฝพฟภมยรลวศษสหฬอฮ",
        "-l tha+eng --oem 1 --psm 6",
        "-l tha+eng --oem 1 --psm 11",
        "-l eng --oem 1 --psm 7 -c tessedit_char_whitelist=0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"
    ];

    $allTexts = [];
    $debugOutputs = [];
    $bestPlate = null;
    $bestScore = -1;
    $bestText = '';

    foreach ($ocrSources as $sourcePath) {
        foreach ($optionsList as $options) {
            $result = runTesseract($tesseractPath, $sourcePath, $options);
            if ($result['text'] !== '') {
                $allTexts[] = $result['text'];
                if ($bestText === '') {
                    $bestText = $result['text'];
                }
                $candidate = extractPlateNumber($result['text']);
                if ($candidate) {
                    $score = scorePlateCandidate($candidate);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestPlate = $candidate;
                        $bestText = $result['text'];
                    }
                }
            }
            if ($result['cmd_output'] !== '') {
                $debugOutputs[] = $result['cmd_output'];
            }
        }
    }

    foreach ($tempImages as $tmp) {
        @unlink($tmp);
    }
    
    if (!$bestPlate) {
        return [
            'success' => false, 
            'error' => 'ไม่สามารถจำแนกป้ายได้ (อ่านได้: ' . $bestText . ')',
            'raw_text' => $bestText,
            'debug' => [
                'attempt_texts' => $allTexts,
                'cmd_output' => implode("\n", array_slice($debugOutputs, 0, 3))
            ]
        ];
    }

    $fixed = postProcessPlate($bestPlate);
    return ['success' => true, 'plate' => strtoupper($fixed), 'raw_text' => $bestText];
}



function extractPlateFromTopBand($tesseractPath, $imagePath) {
    $variants = prepareTopBandVariants($imagePath);
    if (empty($variants)) {
        return ['plate' => null, 'raw_text' => ''];
    }

    $opts = [
        "-l tha+eng --oem 1 --psm 7 -c preserve_interword_spaces=1 -c tessedit_char_whitelist=0123456789กขคฆงจฉชซฌญฎฏฐฑฒณดตถทธนบปผฝพฟภมยรลวศษสหฬอฮABCDEFGHIJKLMNOPQRSTUVWXYZ",
        "-l tha+eng --oem 1 --psm 6 -c tessedit_char_whitelist=0123456789กขคฆงจฉชซฌญฎฏฐฑฒณดตถทธนบปผฝพฟภมยรลวศษสหฬอฮABCDEFGHIJKLMNOPQRSTUVWXYZ"
    ];

    $bestPlate = null;
    $bestScore = -1;
    $rawTexts = [];

    foreach ($variants as $path) {
        foreach ($opts as $opt) {
            $r = runTesseract($tesseractPath, $path, $opt);
            if ($r['text'] !== '') {
                $rawTexts[] = $r['text'];
                $candidate = extractPlateNumber($r['text']);
                if ($candidate) {
                    $score = scorePlateCandidate($candidate) + 3;
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestPlate = $candidate;
                    }
                }
            }
        }
    }

    foreach ($variants as $path) {
        @unlink($path);
    }

    return [
        'plate' => $bestPlate,
        'raw_text' => implode(' | ', array_slice($rawTexts, 0, 4))
    ];
}

function prepareTopBandVariants($imagePath) {
    if (!function_exists('imagecreatefromstring')) {
        return [];
    }

    $raw = @file_get_contents($imagePath);
    if ($raw === false) {
        return [];
    }

    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return [];
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 80 || $h < 80) {
        imagedestroy($src);
        return [];
    }

    // Focus upper text line area of the plate.
    $band = imagecrop($src, [
        'x' => (int) max(0, $w * 0.00),
        'y' => (int) max(0, $h * 0.24),
        'width' => (int) max(1, $w * 1.00),
        'height' => (int) max(1, $h * 0.24)
    ]);
    imagedestroy($src);

    if (!$band) {
        return [];
    }

    imagefilter($band, IMG_FILTER_GRAYSCALE);
    imagefilter($band, IMG_FILTER_CONTRAST, -55);
    imagefilter($band, IMG_FILTER_BRIGHTNESS, 18);

    $bw = makeBinaryImage($band, 178);

    $bwW = imagesx($band);
    $bwH = imagesy($band);
    $targetW = 1800;
    $targetH = (int) max(1, ($bwH / max(1, $bwW)) * $targetW);

    $up1 = imagecreatetruecolor($targetW, $targetH);
    imagecopyresampled($up1, $band, 0, 0, 0, 0, $targetW, $targetH, $bwW, $bwH);

    $up2 = imagecreatetruecolor($targetW, $targetH);
    imagecopyresampled($up2, $bw, 0, 0, 0, 0, $targetW, $targetH, $bwW, $bwH);

    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ocr_top_' . time() . '_' . mt_rand(1000, 9999);
    $p1 = $base . '_g.png';
    $p2 = $base . '_bw.png';
    imagepng($up1, $p1, 0);
    imagepng($up2, $p2, 0);

    imagedestroy($band);
    imagedestroy($bw);
    imagedestroy($up1);
    imagedestroy($up2);

    return [$p1, $p2];
}

function makeBinaryImage($img, $threshold) {
    $w = imagesx($img);
    $h = imagesy($img);
    $out = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($out, 255, 255, 255);
    $black = imagecolorallocate($out, 0, 0, 0);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $gray = (int) (($r * 299 + $g * 587 + $b * 114) / 1000);
            imagesetpixel($out, $x, $y, ($gray < $threshold) ? $black : $white);
        }
    }

    return $out;
}

function postProcessPlate($plate) {
    $plate = normalizePlateCandidate((string) $plate) ?? strtoupper(trim((string) $plate));
    if ($plate === '') {
        return $plate;
    }

    // Convert common Thai-as-Latin OCR, e.g. TI2058 -> กท2058.
    if (preg_match('/^([A-Z]{1,2})(\d{4})$/', $plate, $m)) {
        $thaiPair = latinPairToThai($m[1]);
        if ($thaiPair !== null) {
            $plate = $thaiPair . $m[2];
        }
    }

    $candidates = [$plate];
    // Expand likely OCR ambiguity globally (letters + digits), then resolve with known plates.
    $candidates = array_merge($candidates, expandThaiConfusablePlateVariants($plate));
    $candidates = array_merge($candidates, expandDigitConfusionVariants($plate));
    $candidates = array_values(array_unique($candidates));

    $resolved = resolveByKnownPlates($candidates);
    if ($resolved !== null) {
        return $resolved;
    }

    // If nothing can be resolved from known plates, keep strongest syntactic candidate.
    $best = $plate;
    $bestScore = scorePlateCandidate($plate);
    foreach ($candidates as $c) {
        $score = scorePlateCandidate($c);
        if ($score > $bestScore) {
            $best = $c;
            $bestScore = $score;
        }
    }
    return $best;
}

function expandDigitConfusionVariants($plate) {
    $variants = [];
    if (!preg_match('/^([ก-ฮ]{1,2}|\d[ก-ฮ]{2})(\d{4})$/u', $plate, $m)) {
        return $variants;
    }

    $prefix = $m[1];
    $digits = str_split($m[2]);
    $digitMap = [
        '2' => ['5'], '5' => ['2'],
        '3' => ['8'], '8' => ['3', '0'],
        '0' => ['8', '9'], '9' => ['0'],
        '1' => ['7'], '7' => ['1']
    ];

    for ($i = 0; $i < count($digits); $i++) {
        $d = $digits[$i];
        if (empty($digitMap[$d])) {
            continue;
        }
        foreach ($digitMap[$d] as $alt) {
            $tmp = $digits;
            $tmp[$i] = $alt;
            $variants[] = $prefix . implode('', $tmp);
        }
    }

    return array_values(array_unique($variants));
}

function expandThaiConfusablePlateVariants($plate) {
    $variants = [];
    if (!preg_match('/^([ก-ฮ]{1,2})(\d{4})$/u', $plate, $m)) {
        return $variants;
    }

    $letters = preg_split('//u', $m[1], -1, PREG_SPLIT_NO_EMPTY);
    $digits = $m[2];
    if (!$letters || empty($digits)) {
        return $variants;
    }

    $optionsPerPos = [];
    foreach ($letters as $ch) {
        $opts = thaiConfusableOptions($ch);
        if (!in_array($ch, $opts, true)) {
            array_unshift($opts, $ch);
        }
        $optionsPerPos[] = array_values(array_unique($opts));
    }

    $combinations = [''];
    foreach ($optionsPerPos as $opts) {
        $next = [];
        foreach ($combinations as $prefix) {
            foreach ($opts as $o) {
                $next[] = $prefix . $o;
            }
        }
        $combinations = $next;
    }

    foreach ($combinations as $combo) {
        $candidate = $combo . $digits;
        if ($candidate !== $plate) {
            $variants[] = $candidate;
        }
    }

    return array_values(array_unique($variants));
}

function thaiConfusableOptions($ch) {
    static $map = [
        'ก' => ['ภ', 'ถ', 'ฎ'],
        'ภ' => ['ก', 'ถ'],
        'ถ' => ['ภ', 'ก'],
        'ฎ' => ['ก', 'ญ'],      // Add ญ confusion
        'บ' => ['ภ', 'ป'],
        'ป' => ['บ', 'ภ'],
        'ข' => ['ช', 'ค'],
        'ช' => ['ข', 'ซ'],
        'ซ' => ['ช'],
        'ค' => ['ข', 'ญ'],
        'ท' => ['ห'],
        'ห' => ['ท'],
        'ง' => ['ว'],
        'ว' => ['ง'],
        'ญ' => ['ค', 'น', 'ฎ'],  // Add ฎ confusion
        'น' => ['ญ']
    ];

    return $map[$ch] ?? [$ch];
}

function resolveByKnownPlates($candidates) {
    $known = getKnownPlateSet();
    if (empty($known)) {
        return null;
    }

    $normalizedCandidates = [];
    foreach ($candidates as $candidate) {
        $nc = normalizePlateForCompare($candidate);
        if ($nc === '') {
            continue;
        }
        $normalizedCandidates[] = $nc;
        if (isset($known[$nc])) {
            return $nc;
        }
    }

    // Fuzzy fallback: choose closest known plate if similarity is high enough.
    $bestKnown = null;
    $bestScore = -1.0;
    $secondScore = -1.0;
    foreach ($normalizedCandidates as $nc) {
        foreach ($known as $knownPlateNorm => $knownOriginal) {
            $s = plateSimilarity($nc, $knownPlateNorm);
            if ($s > $bestScore) {
                $secondScore = $bestScore;
                $bestScore = $s;
                $bestKnown = $knownPlateNorm;
            } elseif ($s > $secondScore) {
                $secondScore = $s;
            }
        }
    }

    if ($bestKnown !== null) {
        $margin = $bestScore - max(0.0, $secondScore);
        if ($bestScore >= 0.84 || ($bestScore >= 0.76 && $margin >= 0.08)) {
            return $bestKnown;
        }
    }

    return null;
}

function getKnownPlateSet() {
    static $set = null;
    if ($set !== null) {
        return $set;
    }

    $set = [];
    if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
        return $set;
    }

    $conn = $GLOBALS['conn'];
    $queries = [
        "SELECT plate_number FROM authorized_vehicles",
        "SELECT DISTINCT plate_number FROM vehicle_logs"
    ];

    foreach ($queries as $sql) {
        $res = @$conn->query($sql);
        if (!$res) {
            continue;
        }
        while ($row = $res->fetch_assoc()) {
            $n = normalizePlateForCompare($row['plate_number'] ?? '');
            if ($n !== '') {
                $set[$n] = $row['plate_number'] ?? $n;
            }
        }
        $res->free();
    }

    return $set;
}

function normalizePlateForCompare($plate) {
    $plate = normalizePlateText((string) $plate);
    $plate = preg_replace('/\s+/', '', $plate);
    $plate = preg_replace('/[^0-9A-Zก-ฮ]/u', '', $plate);
    return $plate;
}

function plateSimilarity($a, $b) {
    if ($a === $b) {
        return 1.0;
    }

    $ca = preg_split('//u', (string) $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $cb = preg_split('//u', (string) $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $la = count($ca);
    $lb = count($cb);
    if ($la === 0 || $lb === 0) {
        return 0.0;
    }

    $maxLen = max($la, $lb);
    $minLen = min($la, $lb);
    $score = 0.0;

    for ($i = 0; $i < $minLen; $i++) {
        $x = $ca[$i];
        $y = $cb[$i];
        if ($x === $y) {
            $score += 1.0;
            continue;
        }

        if (ctype_digit($x) && ctype_digit($y)) {
            $digitConf = [
                '2' => ['5'], '5' => ['2'],
                '3' => ['8'], '8' => ['3', '0'],
                '0' => ['8', '9'], '9' => ['0'],
                '1' => ['7'], '7' => ['1']
            ];
            if (isset($digitConf[$x]) && in_array($y, $digitConf[$x], true)) {
                $score += 0.65;
                continue;
            }
        }

        if (preg_match('/[ก-ฮ]/u', $x) && preg_match('/[ก-ฮ]/u', $y)) {
            $opts = thaiConfusableOptions($x);
            if (in_array($y, $opts, true)) {
                $score += 0.65;
                continue;
            }
        }

        $score += 0.0;
    }

    $lengthPenalty = 0.15 * abs($la - $lb);
    $normalized = ($score / $maxLen) - $lengthPenalty;
    return max(0.0, min(1.0, $normalized));
}

function runTesseract($tesseractPath, $imagePath, $options) {
    $imagePathArg = escapeshellarg($imagePath);
    $outputBasePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ocr_output_' . time() . '_' . mt_rand(1000, 9999);
    $outputBaseArg = escapeshellarg($outputBasePath);
    $tesseractCmd = escapeshellarg($tesseractPath);
    $command = "$tesseractCmd $imagePathArg $outputBaseArg $options 2>&1";

    $output = [];
    $returnVar = 0;
    exec($command, $output, $returnVar);

    $textFile = $outputBasePath . '.txt';
    $text = '';
    if (file_exists($textFile)) {
        $text = trim((string) file_get_contents($textFile));
        @unlink($textFile);
    }

    return [
        'text' => $text,
        'code' => $returnVar,
        'cmd_output' => implode("\n", $output)
    ];
}

function extractPlateBySegments($tesseractPath, $imagePath) {
    $segments = preparePlateSegments($imagePath);
    if (!$segments) {
        return ['plate' => null, 'raw_text' => ''];
    }

    $topAttempts = [
        runTesseract($tesseractPath, $segments['top'], "-l tha+eng --oem 1 --psm 7 -c tessedit_char_whitelist=0123456789กขคฆงจฉชซฌญฎฏฐฑฒณดตถทธนบปผฝพฟภมยรลวศษสหฬอฮABCDEFGHIJKLMNOPQRSTUVWXYZ"),
        runTesseract($tesseractPath, $segments['top'], "-l tha+eng --oem 1 --psm 6 -c tessedit_char_whitelist=0123456789กขคฆงจฉชซฌญฎฏฐฑฒณดตถทธนบปผฝพฟภมยรลวศษสหฬอฮABCDEFGHIJKLMNOPQRSTUVWXYZ")
    ];

    $topBestPlate = null;
    $topBestScore = -1;
    foreach ($topAttempts as $attempt) {
        $candidate = extractPlateNumber($attempt['text']);
        if ($candidate) {
            $score = scorePlateCandidate($candidate) + 5;
            if ($score > $topBestScore) {
                $topBestScore = $score;
                $topBestPlate = $candidate;
            }
        }
    }

    $headAttempts = [
        runTesseract($tesseractPath, $segments['head'], "-l eng --oem 1 --psm 10 -c tessedit_char_whitelist=0123456789"),
        runTesseract($tesseractPath, $segments['head'], "-l eng --oem 1 --psm 8 -c tessedit_char_whitelist=0123456789"),
        runTesseract($tesseractPath, $segments['head'], "-l tha+eng --oem 1 --psm 10 -c tessedit_char_whitelist=0123456789")
    ];

    $midAttempts = [
        runTesseract($tesseractPath, $segments['mid'], "-l tha --oem 1 --psm 8 -c tessedit_char_whitelist=กขคฆงจฉชซฌญฎฏฐฑฒณดตถทธนบปผฝพฟภมยรลวศษสหฬอฮ"),
        runTesseract($tesseractPath, $segments['mid'], "-l tha+eng --oem 1 --psm 8 -c tessedit_char_whitelist=กขคฆงจฉชซฌญฎฏฐฑฒณดตถทธนบปผฝพฟภมยรลวศษสหฬอฮABCDEFGHIJKLMNOPQRSTUVWXYZ"),
        runTesseract($tesseractPath, $segments['mid'], "-l eng --oem 1 --psm 8 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ")
    ];

    $tailAttempts = [
        runTesseract($tesseractPath, $segments['tail'], "-l eng --oem 1 --psm 7 -c tessedit_char_whitelist=0123456789"),
        runTesseract($tesseractPath, $segments['tail'], "-l eng --oem 1 --psm 8 -c tessedit_char_whitelist=0123456789"),
        runTesseract($tesseractPath, $segments['tail'], "-l eng --oem 1 --psm 13 -c tessedit_char_whitelist=0123456789")
    ];
https://github.com/
    $headTexts = array_map(function($a) { return $a['text']; }, $headAttempts);
    $midTexts = array_map(function($a) { return $a['text']; }, $midAttempts);
    $tailTexts = array_map(function($a) { return $a['text']; }, $tailAttempts);
    $topTexts = array_map(function($a) { return $a['text']; }, $topAttempts);

    $head = pickBestHeadDigit($headTexts);
    $mid = pickBestThaiPair($midTexts);
    // Also mine digits from top/head/mid OCR results; some photos mis-segment tail area.
    $tail = pickBestTailDigits(array_merge($tailTexts, $headTexts, $midTexts, $topTexts));

    $raw = trim(
        implode(' / ', array_map(function($a) { return $a['text']; }, $topAttempts)) .
        ' || ' .
        implode(' / ', array_map(function($a) { return $a['text']; }, $headAttempts)) .
        ' | ' .
        implode(' / ', array_map(function($a) { return $a['text']; }, $midAttempts)) .
        ' | ' .
        implode(' / ', array_map(function($a) { return $a['text']; }, $tailAttempts))
    );

    foreach ($segments as $p) {
        @unlink($p);
    }

    if ($mid !== null && $tail !== null) {
        $candidates = [];
        $candidates[] = $mid . $tail; // Standard private-car style: กท2058
        if ($head !== null) {
            $candidates[] = $head . $mid . $tail; // Alternate style: 5กข2662
        }

        if ($topBestPlate !== null) {
            $candidates[] = $topBestPlate;
        }

        $best = null;
        $bestScore = -1;
        foreach ($candidates as $candidate) {
            $score = scorePlateCandidate($candidate);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }
        if ($best !== null) {
            return ['plate' => $best, 'raw_text' => $raw];
        }
    }

    if ($topBestPlate !== null) {
        return ['plate' => $topBestPlate, 'raw_text' => $raw];
    }

    return ['plate' => null, 'raw_text' => $raw];
}

function pickBestHeadDigit($texts) {
    $counts = [];
    foreach ($texts as $txt) {
        $d = extractSingleDigit($txt);
        if ($d !== null) {
            $counts[$d] = ($counts[$d] ?? 0) + 1;
        }
    }
    if (empty($counts)) {
        return null;
    }
    arsort($counts);
    return array_key_first($counts);
}

function pickBestThaiPair($texts) {
    $counts = [];
    foreach ($texts as $txt) {
        $pair = extractThaiConsonants2($txt);
        if ($pair !== null) {
            $counts[$pair] = ($counts[$pair] ?? 0) + 1;
        }
        $latinPair = latinPairToThai($txt);
        if ($latinPair !== null) {
            // Slight boost so stable Latin misread (e.g. TI) can win when Thai OCR is noisy.
            $counts[$latinPair] = ($counts[$latinPair] ?? 0) + 2;
        }
    }

    if (!empty($counts)) {
        arsort($counts);
        return array_key_first($counts);
    }

    // Fallback: Latin to Thai correction for OCR confusion (e.g., TI -> กท).
    foreach ($texts as $txt) {
        $pair = latinPairToThai($txt);
        if ($pair !== null) {
            return $pair;
        }
    }
    return null;
}

function pickBestTailDigits($texts) {
    $counts = [];
    foreach ($texts as $txt) {
        $candidates = extractFourDigitsCandidates($txt);
        foreach ($candidates as $idx => $tail) {
            $weight = ($idx === 0) ? 2 : 1;
            $counts[$tail] = ($counts[$tail] ?? 0) + $weight;
        }
    }
    if (empty($counts)) {
        return null;
    }
    arsort($counts);
    return array_key_first($counts);
}

function latinPairToThai($text) {
    $text = strtoupper((string) $text);
    $text = preg_replace('/[^A-Z0-9]/', '', $text);
    if ($text === '') {
        return null;
    }

    // Special-case common confusion from Thai plate font.
    if (strpos($text, 'TI') === 0 || strpos($text, 'T1') === 0) {
        return 'กท';
    }
    if (strpos($text, 'AN') === 0 || strpos($text, '4N') === 0 || strpos($text, 'IN') === 0) {
        return 'กง';
    }

    $map = [
        'T' => 'ท',
        'I' => 'ท',
        'L' => 'ท',
        'K' => 'ก',
        'A' => 'ก',
        'H' => 'ห',
        'N' => 'ง',
        'M' => 'ม',
        'R' => 'ร',
        'Y' => 'ย',
        'P' => 'พ',
        'F' => 'ฟ',
        'C' => 'ค',
        'J' => 'จ',
        'S' => 'ซ',
        'B' => 'บ',
        'D' => 'ต',
        'G' => 'ง',
        'Q' => 'ฮ',
        'O' => 'อ'
    ];

    $result = '';
    for ($i = 0; $i < strlen($text) && mb_strlen($result, 'UTF-8') < 2; $i++) {
        $ch = $text[$i];
        if (isset($map[$ch])) {
            $result .= $map[$ch];
        }
    }

    if (mb_strlen($result, 'UTF-8') >= 2) {
        return mb_substr($result, 0, 2, 'UTF-8');
    }

    return null;
}

function preparePlateSegments($imagePath) {
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }

    $raw = @file_get_contents($imagePath);
    if ($raw === false) {
        return null;
    }

    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return null;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 80 || $h < 80) {
        imagedestroy($src);
        return null;
    }

    // Focus on plate area and top line where number/letters exist.
    $plate = imagecrop($src, [
        'x' => (int) max(0, $w * 0.02),
        'y' => (int) max(0, $h * 0.27),
        'width' => (int) max(1, $w * 0.96),
        'height' => (int) max(1, $h * 0.48)
    ]);

    imagedestroy($src);
    if (!$plate) {
        return null;
    }

    imagefilter($plate, IMG_FILTER_GRAYSCALE);
    imagefilter($plate, IMG_FILTER_CONTRAST, -40);
    imagefilter($plate, IMG_FILTER_BRIGHTNESS, 12);

    $pw = imagesx($plate);
    $ph = imagesy($plate);
    $top = imagecrop($plate, [
        'x' => 0,
        'y' => 0,
        'width' => $pw,
        'height' => (int) max(1, $ph * 0.50)
    ]);
    imagedestroy($plate);
    if (!$top) {
        return null;
    }

    $tw = imagesx($top);
    $th = imagesy($top);

    // Segment zones for [optional leading digit][2 Thai consonants][4 digits]
    $head = imagecrop($top, [
        'x' => (int) max(0, $tw * 0.02),
        'y' => 0,
        'width' => (int) max(1, $tw * 0.12),
        'height' => $th
    ]);
    $mid = imagecrop($top, [
        'x' => (int) max(0, $tw * 0.08),
        'y' => 0,
        'width' => (int) max(1, $tw * 0.26),
        'height' => $th
    ]);
    $tail = imagecrop($top, [
        'x' => (int) max(0, $tw * 0.30),
        'y' => 0,
        'width' => (int) max(1, $tw * 0.70),
        'height' => $th
    ]);
    imagedestroy($top);

    if (!$head || !$mid || !$tail) {
        if ($head) imagedestroy($head);
        if ($mid) imagedestroy($mid);
        if ($tail) imagedestroy($tail);
        return null;
    }

    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ocr_seg_' . time() . '_' . mt_rand(1000, 9999);
    $topPath = $base . '_top.png';
    $headPath = $base . '_head.png';
    $midPath = $base . '_mid.png';
    $tailPath = $base . '_tail.png';

    imagepng($top, $topPath, 0);
    imagepng($head, $headPath, 0);
    imagepng($mid, $midPath, 0);
    imagepng($tail, $tailPath, 0);

    imagedestroy($top);
    imagedestroy($head);
    imagedestroy($mid);
    imagedestroy($tail);

    return ['top' => $topPath, 'head' => $headPath, 'mid' => $midPath, 'tail' => $tailPath];
}

function extractSingleDigit($text) {
    $text = normalizePlateText($text);
    $text = strtr($text, ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'G' => '6', 'B' => '8']);
    if (preg_match('/\d/', $text, $m)) {
        return $m[0];
    }
    return null;
}

function extractThaiConsonants2($text) {
    $text = normalizePlateText($text);
    // Keep only Thai consonants (exclude vowels/tone marks).
    $text = preg_replace('/[^ก-ฮ]/u', '', $text);
    if (mb_strlen($text, 'UTF-8') >= 2) {
        return mb_substr($text, 0, 2, 'UTF-8');
    }
    return null;
}

function extractFourDigitsCandidates($text) {
    $text = normalizePlateText($text);
    $text = strtr($text, [
        'O' => '0', 'Q' => '0', 'D' => '0',
        'I' => '1', 'L' => '1',
        'Z' => '2',
        'S' => '5',
        'G' => '6',
        'B' => '8',
        '?' => '7'
    ]);

    $digitsOnly = preg_replace('/\D/', '', $text);
    if ($digitsOnly === '') {
        return [];
    }

    $results = [];
    if (strlen($digitsOnly) === 3 && preg_match('/^(\d)\1\1$/', $digitsOnly, $m)) {
        // AN999-like OCR for 9999 plates.
        $results[] = $m[1] . $m[1] . $m[1] . $m[1];
    }
    if (strlen($digitsOnly) >= 4) {
        $results[] = substr($digitsOnly, 0, 4);
        for ($i = 1; $i <= strlen($digitsOnly) - 4; $i++) {
            $results[] = substr($digitsOnly, $i, 4);
        }
    }

    return array_values(array_unique($results));
}

function preparePlateImageVariants($imagePath) {
    if (!function_exists('imagecreatefromstring')) {
        return [];
    }

    $raw = @file_get_contents($imagePath);
    if ($raw === false) {
        return [];
    }

    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return [];
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 50 || $h < 50) {
        imagedestroy($src);
        return [];
    }

    // Crop center area where plate text is usually located.
    $cropX = (int) max(0, $w * 0.10);
    $cropY = (int) max(0, $h * 0.27);
    $cropW = (int) min($w - $cropX, $w * 0.80);
    $cropH = (int) min($h - $cropY, $h * 0.48);

    $crop = imagecrop($src, [
        'x' => $cropX,
        'y' => $cropY,
        'width' => $cropW,
        'height' => $cropH
    ]);

    if (!$crop) {
        imagedestroy($src);
        return [];
    }

    // Increase contrast and make OCR-friendly image.
    imagefilter($crop, IMG_FILTER_GRAYSCALE);
    imagefilter($crop, IMG_FILTER_CONTRAST, -35);
    imagefilter($crop, IMG_FILTER_BRIGHTNESS, 10);

    $targetW = 1200;
    $targetH = (int) (($cropH / max(1, $cropW)) * $targetW);
    $upscaled = imagecreatetruecolor($targetW, max(1, $targetH));
    imagecopyresampled($upscaled, $crop, 0, 0, 0, 0, $targetW, max(1, $targetH), $cropW, $cropH);

    $variants = [];
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ocr_plate_prepared_' . time() . '_' . mt_rand(1000, 9999);

    // Variant 1: Full plate area
    $path1 = $base . '_full.png';
    imagepng($upscaled, $path1, 0);
    $variants[] = $path1;

    // Variant 2: Top line only (ignore province line)
    $uw = imagesx($upscaled);
    $uh = imagesy($upscaled);
    $topLine = imagecrop($upscaled, [
        'x' => 0,
        'y' => 0,
        'width' => $uw,
        'height' => (int) max(1, $uh * 0.62)
    ]);
    if ($topLine) {
        $path2 = $base . '_top.png';
        imagepng($topLine, $path2, 0);
        $variants[] = $path2;
        imagedestroy($topLine);
    }

    // Variant 3: Right side digits area
    $rightDigits = imagecrop($upscaled, [
        'x' => (int) max(0, $uw * 0.43),
        'y' => 0,
        'width' => (int) max(1, $uw * 0.57),
        'height' => (int) max(1, $uh * 0.62)
    ]);
    if ($rightDigits) {
        $path3 = $base . '_digits.png';
        imagepng($rightDigits, $path3, 0);
        $variants[] = $path3;
        imagedestroy($rightDigits);
    }

    imagedestroy($src);
    imagedestroy($crop);
    imagedestroy($upscaled);

    return $variants;
}

function findTesseractPath() {
    $paths = [
        'tesseract',  // Linux/Mac default
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',  // Windows Program Files
        'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',  // Windows Program Files (x86)
        'C:\\Users\\' . get_current_user() . '\\AppData\\Local\\Tesseract-OCR\\tesseract.exe', // Windows User AppData
    ];

    foreach ($paths as $path) {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            if (file_exists($path)) {
                return $path;
            }
        } else {
            exec("which $path 2>/dev/null", $output, $returnVar);
            if ($returnVar === 0 && !empty($output)) {
                return trim($output[0]);
            }
        }
    }

    // Try to find via command
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        exec("which tesseract", $output, $returnVar);
        if ($returnVar === 0 && !empty($output)) {
            return trim($output[0]);
        }
    }

    return null;
}

function extractPlateNumber($text) {
    $normalized = normalizePlateText($text);
    $compact = preg_replace('/\s+/', '', $normalized);
    $candidates = [];

    // More lenient extraction: look for any sequence of digits + Thai + digits
    
    // Handle noisy Thai OCR where vowels/marks may be attached to consonants.
    if (preg_match('/(\d)\s*([ก-ฮ])[\x{0E30}-\x{0E4E}]*\s*([ก-ฮ])[\x{0E30}-\x{0E4E}]*\s*([0-9OQDILZSGBA]{4})/u', $normalized, $m0)) {
        $tail = strtr($m0[4], ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'G' => '6', 'B' => '8']);
        if (preg_match('/^\d{4}$/', $tail)) {
            $candidates[] = $m0[1] . $m0[2] . $m0[3] . $tail;
        }
    }

    // Pattern: single digit + 2 Thai + 4 digits
    if (preg_match_all('/\d\s*[ก-ฮ]{2}\s*\d{4}/u', $normalized, $m1)) {
        foreach ($m1[0] as $match) {
            $candidates[] = preg_replace('/\s+/', '', $match);
        }
    }
    
    // Pattern: 2 Thai + 4 digits
    if (preg_match_all('/[ก-ฮ]{2}\s*\d{4}/u', $normalized, $m2)) {
        foreach ($m2[0] as $match) {
            $candidates[] = preg_replace('/\s+/', '', $match);
        }
    }
    
    // Pattern: 1 Thai + 4 digits
    if (preg_match_all('/[ก-ฮ]{1}\s*\d{4}/u', $normalized, $m1b)) {
        foreach ($m1b[0] as $match) {
            $candidates[] = preg_replace('/\s+/', '', $match);
        }
    }
    
    // Pattern: digit + 2 Thai + 4 digits (no spaces)
    if (preg_match_all('/\d[ก-ฮ]{2}\d{4}/u', $compact, $m3)) {
        $candidates = array_merge($candidates, $m3[0]);
    }
    
    // Pattern: 2 Thai + 4 digits (no spaces)
    if (preg_match_all('/[ก-ฮ]{2}\d{4}/u', $compact, $m2b)) {
        $candidates = array_merge($candidates, $m2b[0]);
    }
    
    // Pattern: Latin letters + 4 digits
    if (preg_match_all('/[A-Z]{2,3}\d{4}/', $compact, $m4)) {
        $candidates = array_merge($candidates, $m4[0]);
    }

    // Handle common Thai-as-Latin OCR text, e.g. TI2058 -> กท2058.
    if (preg_match('/^([A-Z]{1,2})(\d{4})$/', $compact, $m5)) {
        $thaiPair = latinPairToThai($m5[1]);
        if ($thaiPair !== null) {
            $candidates[] = $thaiPair . $m5[2];
        }
    }
    if (preg_match('/^([A-Z]{1,2})(\d{3})$/', $compact, $m6) && preg_match('/^(\d)\1\1$/', $m6[2], $dm)) {
        $thaiPair = latinPairToThai($m6[1]);
        if ($thaiPair !== null) {
            $candidates[] = $thaiPair . $dm[1] . $dm[1] . $dm[1] . $dm[1];
        }
    }

    $best = null;
    $bestScore = -1;
    foreach ($candidates as $candidateRaw) {
        $candidate = normalizePlateCandidate($candidateRaw);
        if (!$candidate) {
            continue;
        }
        $score = scorePlateCandidate($candidate);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $candidate;
        }
    }

    return $best;
}

function normalizePlateText($text) {
    $text = strtoupper((string) $text);
    $text = str_replace(["\r", "\n", "\t", '-', '_', '|', '.', ',', ':', ';', '"', "'"], ' ', $text);
    $text = str_replace(['๐','๑','๒','๓','๔','๕','๖','๗','๘','๙'], ['0','1','2','3','4','5','6','7','8','9'], $text);
    // Remove Thai vowel/tone marks so consonant detection is stable (e.g., กี -> ก).
    $text = preg_replace('/[\x{0E31}\x{0E34}-\x{0E3A}\x{0E47}-\x{0E4E}]/u', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function normalizePlateCandidate($candidate) {
    $candidate = normalizePlateText($candidate);
    $candidate = preg_replace('/[^0-9A-Zก-ฮ]/u', '', $candidate);
    if ($candidate === '') {
        return null;
    }

    // Correct common OCR confusion in numeric slots.
    $digitLikeMap = [
        'O' => '0', 'Q' => '0', 'D' => '0',
        'I' => '1', 'L' => '1',
        'Z' => '2',
        'S' => '5',
        'G' => '6',
        'B' => '8'
    ];

    if (preg_match('/^(\d|[OQDILZSGBA])([ก-ฮ]{2})([0-9OQDILZSGBA]{4})$/u', $candidate, $m)) {
        $head = strtr($m[1], $digitLikeMap);
        $thai = $m[2];
        $tail = strtr($m[3], $digitLikeMap);
        if (preg_match('/^\d$/', $head) && preg_match('/^\d{4}$/', $tail)) {
            return $head . $thai . $tail;
        }
    }

    if (preg_match('/^([ก-ฮ]{2})([0-9OQDILZSGBA]{4})$/u', $candidate, $m)) {
        $tail = strtr($m[2], $digitLikeMap);
        if (preg_match('/^\d{4}$/', $tail)) {
            return $m[1] . $tail;
        }
    }

    if (preg_match('/^([ก-ฮ]{1})([0-9OQDILZSGBA]{4})$/u', $candidate, $m)) {
        $tail = strtr($m[2], $digitLikeMap);
        if (preg_match('/^\d{4}$/', $tail)) {
            return $m[1] . $tail;
        }
    }

    if (preg_match('/^[A-Z]{2,3}\d{4}$/', $candidate)) {
        return $candidate;
    }

    return null;
}

function scorePlateCandidate($plate) {
    if (preg_match('/^[ก-ฮ]{1}\d{4}$/u', $plate)) {
        return 108;
    }
    if (preg_match('/^[ก-ฮ]{2}\d{4}$/u', $plate)) {
        return 110;
    }
    if (preg_match('/^\d[ก-ฮ]{2}\d{4}$/u', $plate)) {
        return 100;
    }
    if (preg_match('/^[A-Z]{2,3}\d{4}$/', $plate)) {
        return 60;
    }
    return 0;
}

function getRequestHeaderValue($headerName) {
    $normalizedTarget = strtoupper(str_replace('-', '_', $headerName));

    // Fallback for environments where getallheaders() is unavailable.
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') !== 0) {
            continue;
        }
        if (strtoupper(substr($key, 5)) === $normalizedTarget) {
            return (string) $value;
        }
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strtoupper(str_replace('-', '_', $key)) === $normalizedTarget) {
                return (string) $value;
            }
        }
    }

    return '';
}

function isOcrRequestAuthorized() {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // Allow dashboard users who already logged in.
    if (!empty($_SESSION['user'])) {
        return true;
    }

    if (!defined('PARKING_OCR_API_KEY') || PARKING_OCR_API_KEY === '') {
        return false;
    }

    $providedKey = trim(getRequestHeaderValue('X-API-Key'));
    if ($providedKey === '') {
        return false;
    }

    return hash_equals(PARKING_OCR_API_KEY, $providedKey);
}

// API endpoint for OCR
if (isset($_POST['action']) && $_POST['action'] === 'extract_plate') {
    // Keep suppression scoped to OCR API response only.
    error_reporting(0);
    ini_set('display_errors', '0');
    if (ob_get_level() === 0) {
        ob_start();
    }
    if (ob_get_level() > 0) {
        ob_clean();  // Clear any previous output
    }
    header('Content-Type: application/json; charset=UTF-8');

    if (!isOcrRequestAuthorized()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized: API key is required',
            'version' => OCR_ENGINE_VERSION
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(__FILE__, true);
    }
    
    if (!isset($_FILES['image'])) {
        echo json_encode(['success' => false, 'error' => 'ไม่พบไฟล์รูป', 'version' => OCR_ENGINE_VERSION]);
        exit;
    }

    $file = $_FILES['image'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (!in_array($fileExt, $allowedExts)) {
        echo json_encode(['success' => false, 'error' => 'รูปแบบไฟล์ไม่รองรับ', 'version' => OCR_ENGINE_VERSION]);
        exit;
    }

    if ($file['size'] > 5242880) { // 5MB
        echo json_encode(['success' => false, 'error' => 'ไฟล์ใหญ่เกินไป (สูงสุด 5MB)', 'version' => OCR_ENGINE_VERSION]);
        exit;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'ข้อผิดพลาดในการอัพโหลด', 'version' => OCR_ENGINE_VERSION]);
        exit;
    }

    // Create temp file for OCR processing
    $tempFile = sys_get_temp_dir() . '/plate_' . time() . '.' . $fileExt;
    if (!move_uploaded_file($file['tmp_name'], $tempFile)) {
        echo json_encode(['success' => false, 'error' => 'ไม่สามารถบันทึกไฟล์ชั่วคราวได้', 'version' => OCR_ENGINE_VERSION]);
        exit;
    }

    // Extract plate
    $result = extractPlateFromImage($tempFile);
    
    // Clean up temp file
    @unlink($tempFile);

    $result['version'] = OCR_ENGINE_VERSION;
    
    if (ob_get_level() > 0) {
        ob_clean();  // Clear any debug output before sending JSON
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

?>

<?php
// backend.php
// بسيط وآمن: يقرأ المتغيرات من .env (إن وُجد) ويخلّص طلب Azure OpenAI.
// لا تنسي: ضعي ملف .env في نفس المجلد بعد رفعه على Hostinger.

function load_env($path = __DIR__ . '/../.env') 
 {
    $vars = [];
    if (!file_exists($path)) return $vars;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) == 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            $val = trim($val, "\"'");
            $vars[$key] = $val;
        }
    }
    return $vars;
}

$env = load_env();

$AZURE_ENDPOINT = rtrim($env['AZURE_ENDPOINT'] ?? getenv('AZURE_ENDPOINT') ?? '', '/');
$AZURE_DEPLOYMENT_NAME = $env['AZURE_DEPLOYMENT_NAME'] ?? getenv('AZURE_DEPLOYMENT_NAME') ?? '';
$AZURE_API_VERSION = $env['AZURE_API_VERSION'] ?? getenv('AZURE_API_VERSION') ?? '2025-01-01-preview';
$AZURE_API_KEY = $env['AZURE_API_KEY'] ?? getenv('AZURE_API_KEY') ?? '';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['imageData'])) {
    http_response_code(400);
    echo json_encode(['error' => 'يرجى إرسال imageData (data URL) في body كـ JSON.']);
    exit;
}

$imageData = $input['imageData'];
$regenerate = isset($input['regenerate']) ? (bool)$input['regenerate'] : false;

if (empty($AZURE_ENDPOINT) || empty($AZURE_DEPLOYMENT_NAME) || empty($AZURE_API_KEY)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration error: تأكد من إعداد .env بالقيم المطلوبة.']);
    exit;
}

// Build request URL
$url = $AZURE_ENDPOINT . "/openai/deployments/" . $AZURE_DEPLOYMENT_NAME . "/chat/completions?api-version=" . $AZURE_API_VERSION;

// Compose messages: first a short-description prompt, then request for marketing descriptions
$shortPrompt = "حلل هذه الصورة واكتب وصفًا قصيرًا للمنتج في 3-5 كلمات باللغة العربية. ركز على نوع المنتج، اللون إن كان بارزًا، والميزة الأهم.";
$marketingPrompt = "بناءً على هذا الوصف القصير: \"{SHORT}\"\nأنشئ 5 أوصاف تسويقية مختلفة باللغة العربية:\n1) وصف قصير وجذاب (10-15 كلمة)\n2) وصف متوسط مع المميزات (20-30 كلمة)\n3) وصف تفصيلي للمتجر الإلكتروني (40-50 كلمة)\n4) وصف إعلاني مؤثر (15-20 كلمة)\n5) وصف لوسائل التواصل الاجتماعي مع هاشتاغات (25-35 كلمة)\nاكتب كل وصف في سطر منفصل مع رقمه.";

// First: ask for short description
$payload1 = [
    "messages" => [
        [
            "role" => "user",
            "content" => [
                ["type" => "text", "text" => $shortPrompt],
                ["type" => "image_url", "image_url" => ["url" => $imageData]]
            ]
        ]
    ],
    "max_tokens" => 80
];

$headers = [
    "Content-Type: application/json",
    "api-key: " . $AZURE_API_KEY
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload1));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response1 = curl_exec($ch);
$err1 = curl_error($ch);
$httpcode1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($err1 || $httpcode1 >= 400) {
    http_response_code(500);
    echo json_encode(['error' => 'خطأ عند طلب الوصف القصير: ' . ($err1 ?: $response1)]);
    exit;
}

$data1 = json_decode($response1, true);
$shortDesc = '';
if (is_array($data1) && isset($data1['choices'][0]['message']['content'])) {
    $shortDesc = trim($data1['choices'][0]['message']['content']);
} else {
    $shortDesc = trim($response1);
}

// Now: ask for marketing descriptions using the short description
$finalMarketingPrompt = str_replace("{SHORT}", $shortDesc, $marketingPrompt);

$payload2 = [
    "messages" => [
        [
            "role" => "user",
            "content" => $finalMarketingPrompt
        ]
    ],
    "max_tokens" => 600
];

curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload2));
$response2 = curl_exec($ch);
$err2 = curl_error($ch);
$httpcode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($err2 || $httpcode2 >= 400) {
    http_response_code(500);
    echo json_encode(['error' => 'خطأ عند إنشاء الأوصاف التسويقية: ' . ($err2 ?: $response2)]);
    exit;
}

$data2 = json_decode($response2, true);
$content = '';
if (is_array($data2) && isset($data2['choices'][0]['message'])) {
    // message could be text or structured; try to extract content
    $msg = $data2['choices'][0]['message'];
    if (is_string($msg['content'] ?? null)) {
        $content = trim($msg['content']);
    } elseif (is_array($msg['content'])) {
        // concatenate text pieces
        $parts = [];
        foreach ($msg['content'] as $c) {
            if (isset($c['text'])) $parts[] = $c['text'];
            if (isset($c['caption'])) $parts[] = $c['caption'];
            if (isset($c['plain_text'])) $parts[] = $c['plain_text'];
        }
        $content = trim(implode("\\n", array_filter($parts)));
    } else {
        $content = json_encode($msg);
    }
} else {
    $content = trim($response2);
}

// Parse descriptions into array
$lines = preg_split('/\r\n|\r|\n/', $content);
$descriptions = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '') continue;
    // remove leading numbering
    $line = preg_replace('/^\d+\.\s*/', '', $line);
    $descriptions[] = $line;
}

$result = [
    'short' => $shortDesc,
    'descriptions' => $descriptions,
    // include raw responses for debugging (optional)
    'raw_short' => $data1,
    'raw_marketing' => $data2
];

echo json_encode($result, JSON_UNESCAPED_UNICODE);
?>

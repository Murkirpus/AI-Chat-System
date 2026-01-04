<?php
/**
 * 🤖 AI Chat з OpenRouter API
 * Версія: 6.0 - Найкращі безкоштовні + дешеві платні моделі
 */

define('OPENROUTER_API_KEY', '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');
define('SITE_URL', 'http://dj-x.info');
define('SITE_NAME', 'AI Chat');
define('CHAT_VERSION', '6.0');

// Моделі: 🆓 = безкоштовна, 💰 = платна (ціна за 1M токенів input/output)
$freeModels = [
    // ═══════════════════════════════════════════════════════════════
    // 🆓 БЕЗКОШТОВНІ МОДЕЛІ (FREE)
    // ═══════════════════════════════════════════════════════════════
    
    // ⭐ ТОП безкоштовні
    'moonshotai/kimi-k2:free' => '🆓 ⭐ Kimi K2 (1T параметрів) - найрозумніша',
    'deepseek/deepseek-r1-0528:free' => '🆓 ⭐ DeepSeek R1 - reasoning/логіка',
    'meta-llama/llama-3.1-405b-instruct:free' => '🆓 ⭐ Llama 3.1 405B - найбільша',
    'google/gemini-2.0-flash-exp:free' => '🆓 ⭐ Gemini 2.0 Flash - швидка',
    'qwen/qwen3-coder:free' => '🆓 ⭐ Qwen3 Coder 480B - для коду',
    
    // DeepSeek & варіанти
    'tngtech/deepseek-r1t2-chimera:free' => '🆓 DeepSeek R1T2 Chimera',
    'nex-agi/deepseek-v3.1-nex-n1:free' => '🆓 DeepSeek V3.1 Nex',
    
    // Meta Llama
    'meta-llama/llama-3.3-70b-instruct:free' => '🆓 Llama 3.3 70B',
    'nousresearch/hermes-3-llama-3.1-405b:free' => '🆓 Hermes 3 405B',
    
    // Google Gemma
    'google/gemma-3-27b-it:free' => '🆓 Gemma 3 27B',
    'google/gemma-3-12b-it:free' => '🆓 Gemma 3 12B',
    
    // Qwen
    'qwen/qwen3-4b:free' => '🆓 Qwen3 4B (швидка)',
    
    // Nvidia
    'nvidia/nemotron-3-nano-30b-a3b:free' => '🆓 Nemotron 3 30B',
    
    // Mistral
    'mistralai/devstral-2512:free' => '🆓 Devstral (для коду)',
    'mistralai/mistral-small-3.1-24b-instruct:free' => '🆓 Mistral Small 3.1 24B',
    'mistralai/mistral-7b-instruct:free' => '🆓 Mistral 7B',
    
    // Інші безкоштовні
    'openai/gpt-oss-120b:free' => '🆓 GPT OSS 120B',
    'allenai/olmo-3.1-32b-think:free' => '🆓 Olmo 3.1 32B Think',
    'z-ai/glm-4.5-air:free' => '🆓 GLM 4.5 Air',
    
    // ═══════════════════════════════════════════════════════════════
    // 💰 ПЛАТНІ МОДЕЛІ (дешеві, ціна за 1M токенів)
    // ═══════════════════════════════════════════════════════════════
    
    // OpenAI - найдешевші
    'openai/gpt-4o-mini' => '💰 GPT-4o Mini ($0.15/$0.60) ⚡ найдешевша OpenAI',
    'openai/gpt-4o-mini-2024-07-18' => '💰 GPT-4o Mini 2024-07 ($0.15/$0.60)',
    'openai/gpt-4.1-nano' => '💰 GPT-4.1 Nano ($0.10/$0.40) 🔥 супердешева',
    
    // Anthropic Claude - дешеві
    'anthropic/claude-3-haiku' => '💰 Claude 3 Haiku ($0.25/$1.25) ⚡ швидка',
    'anthropic/claude-3.5-haiku' => '💰 Claude 3.5 Haiku ($0.80/$4) 🧠 розумніша',
    
    // Google Gemini - дешеві
    'google/gemini-flash-1.5' => '💰 Gemini 1.5 Flash ($0.075/$0.30) 🔥 найдешевша',
    'google/gemini-2.0-flash' => '💰 Gemini 2.0 Flash ($0.10/$0.40)',
    'google/gemini-flash-1.5-8b' => '💰 Gemini 1.5 Flash 8B ($0.0375/$0.15) 💎',
    
    // DeepSeek - дуже дешеві
    'deepseek/deepseek-chat' => '💰 DeepSeek Chat ($0.14/$0.28) 🔥 найкраща ціна/якість',
    'deepseek/deepseek-coder' => '💰 DeepSeek Coder ($0.14/$0.28) 💻 для коду',
    
    // Mistral - дешеві
    'mistralai/mistral-small-latest' => '💰 Mistral Small ($0.10/$0.30)',
    'mistralai/mistral-nemo' => '💰 Mistral Nemo ($0.035/$0.08) 💎 мікро-ціна',
    
    // Qwen платні
    'qwen/qwen-2.5-72b-instruct' => '💰 Qwen 2.5 72B ($0.23/$0.23)',
    'qwen/qwen-2.5-coder-32b-instruct' => '💰 Qwen Coder 32B ($0.08/$0.08) 💻',
    
    // xAI Grok - дешеві варіанти
    'x-ai/grok-2-mini' => '💰 Grok 2 Mini ($0.30/$0.50)',
    
    // Meta Llama платні (швидші)
    'meta-llama/llama-3.1-8b-instruct' => '💰 Llama 3.1 8B ($0.02/$0.05) 🔥',
    'meta-llama/llama-3.2-3b-instruct' => '💰 Llama 3.2 3B ($0.01/$0.02) 💎',
    
    // ═══════════════════════════════════════════════════════════════
    // 💎 ПРЕМІУМ (якісніші, але дорожчі)
    // ═══════════════════════════════════════════════════════════════
    
    'openai/gpt-4o' => '💎 GPT-4o ($2.50/$10) - флагман OpenAI',
    'anthropic/claude-sonnet-4' => '💎 Claude Sonnet 4 ($3/$15) - найкращий для тексту',
    'google/gemini-2.5-pro' => '💎 Gemini 2.5 Pro ($1.25/$10) - найкращий Google',
    'x-ai/grok-3' => '💎 Grok 3 ($3/$15) - xAI флагман',
];

$defaultModel = 'openai/gpt-4.1-nano';

$systemPrompt = <<<PROMPT
You are a friendly and persuasive movie recommendation consultant for the online cinema "КиноПростор".

Your main goal:
Increase user engagement and motivate users to start watching movies or TV series immediately.

About the platform:
КиноПростор is a modern online cinema with over 50,000 movies and series available with Ukrainian dubbing.
The service is completely free and works on any device in HD and 4K quality.

Key advantages you must highlight naturally in conversation:
🎬 Huge library (50,000+ films)
🇺🇦 Ukrainian dubbing
🆓 Free access without subscriptions
📱 Available on any device
⚡ High quality (HD / 4K)

Your tasks:
– Recommend movies and series based on user interests, mood, or genre
– Use emotional, engaging language
– Emphasize how enjoyable and convenient watching will be
– Encourage users to start watching right now
– Suggest 2–4 titles per recommendation
– Offer alternatives ("If you like this, you'll also enjoy…")

Available categories:
Action, Comedy, Drama, Sci-Fi, Horror, Animation, TV Series, Anime.

Communication style:
– Friendly, warm, and enthusiastic
– Light sales tone (no pressure)
– Use emojis 🎬🍿🔥✨
– Short, clear, and engaging messages
– Ask soft follow-up questions to continue the dialogue

Language:
Respond ONLY in Russian.

Contact info (use only if relevant):
Website: https://kinoprostor.xyz
Email: info@kinoprostor.tv
PROMPT;

session_start();

if (!isset($_SESSION['chat_version']) || $_SESSION['chat_version'] !== CHAT_VERSION) {
    $_SESSION['chat_history'] = [];
    $_SESSION['selected_model'] = $defaultModel;
    $_SESSION['chat_version'] = CHAT_VERSION;
}

if (!isset($_SESSION['chat_history'])) $_SESSION['chat_history'] = [];
if (!isset($_SESSION['selected_model']) || !array_key_exists($_SESSION['selected_model'], $freeModels)) {
    $_SESSION['selected_model'] = $defaultModel;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'send_message':
            $userMessage = trim($_POST['message'] ?? '');
            if (empty($userMessage)) {
                echo json_encode(['error' => 'Повідомлення порожнє']);
                exit;
            }
            
            $_SESSION['chat_history'][] = ['role' => 'user', 'content' => $userMessage];
            
            $response = sendToOpenRouter($_SESSION['chat_history'], $systemPrompt, $_SESSION['selected_model']);
            
            if (isset($response['error'])) {
                array_pop($_SESSION['chat_history']);
                echo json_encode(['error' => $response['error']]);
                exit;
            }
            
            $_SESSION['chat_history'][] = ['role' => 'assistant', 'content' => $response['message']];
            
            echo json_encode([
                'success' => true,
                'message' => $response['message'],
                'model' => $response['model'] ?? $_SESSION['selected_model']
            ]);
            exit;
            
        case 'clear_history':
            $_SESSION['chat_history'] = [];
            echo json_encode(['success' => true]);
            exit;
            
        case 'change_model':
            global $freeModels;
            $model = $_POST['model'] ?? '';
            if (array_key_exists($model, $freeModels)) {
                $_SESSION['selected_model'] = $model;
                echo json_encode(['success' => true, 'model' => $model]);
            } else {
                echo json_encode(['error' => 'Модель не знайдена']);
            }
            exit;
            
        case 'get_history':
            echo json_encode([
                'success' => true,
                'history' => $_SESSION['chat_history'],
                'model' => $_SESSION['selected_model']
            ]);
            exit;
    }
}

function sendToOpenRouter($chatHistory, $systemPrompt, $model) {
    $apiKey = OPENROUTER_API_KEY;
    
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    
    foreach (array_slice($chatHistory, -20) as $msg) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }
    
    $data = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 2048,
        'temperature' => 0.7,
    ];
    
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: ' . SITE_URL,
            'X-Title: ' . SITE_NAME,
        ],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 120,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => 'З\'єднання: ' . $error];
    
    $result = json_decode($response, true);
    
    if ($httpCode !== 200) {
        $errorMsg = $result['error']['message'] ?? $result['message'] ?? 'Помилка';
        if ($httpCode === 401) return ['error' => '🔑 Невірний API ключ'];
        if ($httpCode === 402) return ['error' => '💳 Недостатньо кредитів'];
        if ($httpCode === 404) return ['error' => '🔧 Модель недоступна - оберіть іншу'];
        if ($httpCode === 429) return ['error' => '⏳ Забагато запитів, зачекайте'];
        if ($httpCode === 503) return ['error' => '🔧 Сервер перевантажений'];
        return ['error' => "($httpCode): $errorMsg"];
    }
    
    if (isset($result['choices'][0]['message']['content'])) {
        return [
            'message' => $result['choices'][0]['message']['content'],
            'model' => $result['model'] ?? $model
        ];
    }
    
    return ['error' => 'Некоректна відповідь'];
}

// Функція для визначення типу моделі
function getModelType($name) {
    if (strpos($name, '🆓') === 0) return 'free';
    if (strpos($name, '💰') === 0) return 'paid';
    if (strpos($name, '💎') === 0) return 'premium';
    return 'other';
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>🤖 AI Chat v6</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        :root{--primary:#6366f1;--bg:#0f172a;--card:#1e293b;--input:#334155;--text:#f1f5f9;--muted:#94a3b8;--user:#3b82f6;--bot:#475569;--ok:#22c55e;--err:#ef4444;--free:#10b981;--paid:#f59e0b;--premium:#a855f7;--header-h:60px;--input-h:140px}
        html,body{height:100%;overflow:hidden}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);display:flex;flex-direction:column}
        
        /* Фіксований header */
        .header{background:var(--card);padding:1rem;border-bottom:1px solid rgba(255,255,255,.1);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;flex-shrink:0;height:var(--header-h);position:fixed;top:0;left:0;right:0;z-index:100}
        .header h1{font-size:1.25rem}
        .controls{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap}
        select{background:var(--input);color:var(--text);border:1px solid rgba(255,255,255,.1);padding:.5rem;border-radius:.5rem;max-width:400px;font-size:.85rem}
        select optgroup{font-weight:bold;color:var(--muted)}
        select option{padding:4px}
        .btn{background:var(--primary);color:#fff;border:none;padding:.5rem 1rem;border-radius:.5rem;cursor:pointer}
        .btn-err{background:var(--err)}
        
        /* Основний контейнер чату */
        .chat{position:fixed;top:var(--header-h);bottom:var(--input-h);left:0;right:0;max-width:900px;width:100%;margin:0 auto;display:flex;flex-direction:column}
        
        /* Область повідомлень - прокручується */
        .msgs{flex:1;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:1rem}
        
        /* Фіксована область введення */
        .input-wrapper{position:fixed;bottom:0;left:0;right:0;background:var(--bg);border-top:1px solid rgba(255,255,255,.1);z-index:100}
        .input-container{max-width:900px;margin:0 auto;padding:1rem}
        .input-area{background:var(--card);border-radius:1rem;padding:.75rem;display:flex;gap:.75rem;align-items:flex-end}
        textarea{flex:1;background:var(--input);border:none;color:var(--text);padding:.75rem 1rem;border-radius:.75rem;font-size:1rem;font-family:inherit;resize:none;min-height:48px;max-height:100px}
        textarea:focus{outline:2px solid var(--primary)}
        .send{background:var(--primary);color:#fff;border:none;width:48px;height:48px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .send:disabled{background:var(--input);cursor:not-allowed}
        .send svg{width:20px;height:20px}
        .status{font-size:.75rem;color:var(--muted);text-align:center;padding:.5rem 0}
        .status.ok{color:var(--ok)}
        .status.err{color:var(--err)}
        .status.free{color:var(--free)}
        .status.paid{color:var(--paid)}
        .status.premium{color:var(--premium)}
        .legend{font-size:.7rem;color:var(--muted);text-align:center;padding-bottom:.5rem;opacity:.7}
        
        /* Повідомлення */
        .msg{display:flex;gap:.75rem;max-width:85%;animation:fade .3s}
        @keyframes fade{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
        .msg.user{align-self:flex-end;flex-direction:row-reverse}
        .avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
        .msg.user .avatar{background:var(--user)}
        .msg.bot .avatar{background:var(--bot)}
        .content{background:var(--card);padding:.875rem 1.125rem;border-radius:1rem;line-height:1.6;word-wrap:break-word}
        .msg.user .content{background:var(--user);border-bottom-right-radius:.25rem}
        .msg.bot .content{border-bottom-left-radius:.25rem}
        .typing{display:flex;gap:.25rem;padding:.5rem}
        .typing span{width:8px;height:8px;background:var(--muted);border-radius:50%;animation:type 1.4s infinite}
        .typing span:nth-child(2){animation-delay:.2s}
        .typing span:nth-child(3){animation-delay:.4s}
        @keyframes type{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-10px)}}
        
        /* Welcome */
        .welcome{text-align:center;padding:3rem 1rem;color:var(--muted)}
        .welcome h2{color:var(--text);margin-bottom:.5rem}
        .welcome p{margin-bottom:1rem}
        .model-info{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;margin:1rem 0;font-size:.8rem}
        .model-info span{padding:.25rem .75rem;border-radius:1rem}
        .model-info .free{background:rgba(16,185,129,.2);color:var(--free)}
        .model-info .paid{background:rgba(245,158,11,.2);color:var(--paid)}
        .model-info .premium{background:rgba(168,85,247,.2);color:var(--premium)}
        .suggestions{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center}
        .sug{background:var(--card);border:1px solid rgba(255,255,255,.1);color:var(--text);padding:.5rem 1rem;border-radius:2rem;cursor:pointer}
        .sug:hover{border-color:var(--primary)}
        
        /* Toast */
        .toast{position:fixed;bottom:160px;left:50%;transform:translateX(-50%);background:var(--err);color:#fff;padding:.75rem 1.5rem;border-radius:.5rem;z-index:1000;max-width:90%}
        
        .badge{font-size:.7rem;color:var(--muted);margin-top:.5rem;opacity:.7}
        
        /* Mobile */
        @media(max-width:640px){
            :root{--header-h:auto;--input-h:130px}
            .header{position:relative;height:auto;padding:.75rem}
            .header h1{font-size:1rem}
            select{max-width:200px;font-size:.75rem}
            .chat{position:relative;top:0;bottom:0;height:calc(100vh - var(--input-h));overflow:hidden}
            .msg{max-width:95%}
            .input-container{padding:.75rem}
        }
    </style>
</head>
<body>
<header class="header">
    <h1>🤖 AI Chat v6</h1>
    <div class="controls">
        <select id="model">
            <optgroup label="🆓 БЕЗКОШТОВНІ">
                <?php foreach($freeModels as $id=>$name): if(strpos($name,'🆓')===0):?>
                <option value="<?=htmlspecialchars($id)?>"<?=$_SESSION['selected_model']===$id?' selected':''?>><?=htmlspecialchars($name)?></option>
                <?php endif; endforeach;?>
            </optgroup>
            <optgroup label="💰 ПЛАТНІ (дешеві)">
                <?php foreach($freeModels as $id=>$name): if(strpos($name,'💰')===0):?>
                <option value="<?=htmlspecialchars($id)?>"<?=$_SESSION['selected_model']===$id?' selected':''?>><?=htmlspecialchars($name)?></option>
                <?php endif; endforeach;?>
            </optgroup>
            <optgroup label="💎 ПРЕМІУМ">
                <?php foreach($freeModels as $id=>$name): if(strpos($name,'💎')===0):?>
                <option value="<?=htmlspecialchars($id)?>"<?=$_SESSION['selected_model']===$id?' selected':''?>><?=htmlspecialchars($name)?></option>
                <?php endif; endforeach;?>
            </optgroup>
        </select>
        <button class="btn btn-err" onclick="clearChat()">🗑️</button>
    </div>
</header>

<div class="chat">
    <div class="msgs" id="msgs">
        <div class="welcome" id="welcome">
            <h2>👋 Вітаємо в AI Chat!</h2>
            <p>Обрана модель: <strong><?=htmlspecialchars($freeModels[$_SESSION['selected_model']]??$_SESSION['selected_model'])?></strong></p>
            <div class="model-info">
                <span class="free">🆓 Безкоштовні: <?=count(array_filter($freeModels, fn($n)=>strpos($n,'🆓')===0))?></span>
                <span class="paid">💰 Платні: <?=count(array_filter($freeModels, fn($n)=>strpos($n,'💰')===0))?></span>
                <span class="premium">💎 Преміум: <?=count(array_filter($freeModels, fn($n)=>strpos($n,'💎')===0))?></span>
            </div>
            <div class="suggestions">
                <button class="sug" onclick="send('Привіт!')">👋 Привіт</button>
                <button class="sug" onclick="send('Порадь фільм')">🎬 Фільм</button>
                <button class="sug" onclick="send('Новинки?')">🆕 Новинки</button>
            </div>
        </div>
    </div>
</div>

<div class="input-wrapper">
    <div class="input-container">
        <div class="input-area">
            <textarea id="inp" placeholder="Повідомлення..." rows="1" onkeydown="key(event)"></textarea>
            <button class="send" id="btn" onclick="sendMsg()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg></button>
        </div>
        <div class="status" id="status">🟢 Готовий</div>
        <div class="legend">🆓 безкоштовно | 💰 платно | 💎 преміум</div>
    </div>
</div>

<script>
const msgs=document.getElementById('msgs'),inp=document.getElementById('inp'),btn=document.getElementById('btn'),model=document.getElementById('model'),status=document.getElementById('status'),welcome=document.getElementById('welcome');
let loading=false;

inp.addEventListener('input',function(){this.style.height='auto';this.style.height=Math.min(this.scrollHeight,100)+'px'});

model.addEventListener('change',async function(){
    const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=change_model&model='+encodeURIComponent(this.value)});
    const d=await r.json();
    if(d.success){
        const txt=this.options[this.selectedIndex].text;
        const type=txt.startsWith('🆓')?'free':txt.startsWith('💰')?'paid':txt.startsWith('💎')?'premium':'';
        stat('✅ '+txt.substring(0,50)+'...',type);
    } else err(d.error||'Помилка');
});

function key(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMsg()}}

async function sendMsg(){
    const m=inp.value.trim();if(!m||loading)return;
    if(welcome)welcome.style.display='none';
    addMsg(m,'user');inp.value='';inp.style.height='auto';
    loading=true;btn.disabled=true;
    const t=typing();stat('⏳ Думаю...','');
    try{
        const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=send_message&message='+encodeURIComponent(m)});
        const d=await r.json();t.remove();
        if(d.error){err(d.error);stat('❌ Помилка','err')}
        else{
            addMsg(d.message,'bot',d.model);
            const n=d.model?d.model.split('/').pop().split(':')[0]:'';
            const isFree=d.model&&d.model.includes(':free');
            stat('🟢 '+n,isFree?'free':'paid');
        }
    }catch(e){t.remove();err('З\'єднання');stat('❌','err')}
    loading=false;btn.disabled=false;inp.focus();
}

function send(t){inp.value=t;sendMsg()}

function addMsg(c,type,model){
    const el=document.createElement('div');el.className='msg '+type;
    const av=type==='user'?'👤':'🤖';
    let badge='';
    if(type==='bot'&&model){
        const n=model.split('/').pop().split(':')[0];
        const isFree=model.includes(':free');
        badge='<div class="badge">'+(isFree?'🆓':'💰')+' '+n+'</div>';
    }
    el.innerHTML='<div class="avatar">'+av+'</div><div class="content">'+fmt(c)+badge+'</div>';
    msgs.appendChild(el);msgs.scrollTop=msgs.scrollHeight;
}

function fmt(t){
    t=t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    t=t.replace(/```(\w*)\n?([\s\S]*?)```/g,'<pre><code>$2</code></pre>');
    t=t.replace(/`([^`]+)`/g,'<code>$1</code>');
    t=t.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>');
    t=t.replace(/\*([^*]+)\*/g,'<em>$1</em>');
    return t.replace(/\n/g,'<br>');
}

function typing(){
    const el=document.createElement('div');el.className='msg bot';
    el.innerHTML='<div class="avatar">🤖</div><div class="content"><div class="typing"><span></span><span></span><span></span></div></div>';
    msgs.appendChild(el);msgs.scrollTop=msgs.scrollHeight;return el;
}

async function clearChat(){if(!confirm('Очистити?'))return;await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=clear_history'});location.reload()}

function err(m){const t=document.createElement('div');t.className='toast';t.textContent=m;document.body.appendChild(t);setTimeout(()=>t.remove(),6000)}

function stat(t,c){status.textContent=t;status.className='status '+(c||'')}

(async()=>{
    try{
        const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=get_history'});
        const d=await r.json();
        if(d.history&&d.history.length>0){welcome.style.display='none';d.history.forEach(m=>addMsg(m.content,m.role==='user'?'user':'bot'))}
    }catch(e){}
})();
inp.focus();
</script>
</body>
</html>
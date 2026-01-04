<?php
/**
 * 🤖 AI Chat з OpenRouter API + RAG (Векторний пошук)
 * Версія: 7.1 - оптимізований RAG (чанки 500, similarity 0.75+)
 */

// Збільшуємо ліміти для великих текстів
ini_set('memory_limit', '256M');
ini_set('max_execution_time', '120');

define('OPENROUTER_API_KEY', 'sk-or-v1-');
define('SITE_URL', 'http://dj-x.info');
define('SITE_NAME', 'AI Chat');
define('CHAT_VERSION', '7.1');

// PostgreSQL налаштування
define('PG_HOST', 'localhost');
define('PG_PORT', '5432');
define('PG_DB', 'vector_db');
define('PG_USER', 'postgres');
define('PG_PASS', ''); // Змініть на свій пароль

// Embedding модель
define('EMBEDDING_MODEL', 'openai/text-embedding-ada-002');
define('EMBEDDING_DIM', 1536);

// ═══════════════════════════════════════════════════════════════
// 🔍 КЛАС ВЕКТОРНОГО ПОШУКУ
// ═══════════════════════════════════════════════════════════════

class VectorRAG {
    private ?PDO $db = null;
    private string $apiKey;
    private string $embeddingUrl = 'https://openrouter.ai/api/v1/embeddings';
    private int $chunkSize = 500;
    private int $chunkOverlap = 100;
    
    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }
    
    // Підключення до БД (ліниве)
    private function getDb(): PDO {
        if ($this->db === null) {
            try {
                $this->db = new PDO(
                    sprintf('pgsql:host=%s;port=%s;dbname=%s', PG_HOST, PG_PORT, PG_DB),
                    PG_USER,
                    PG_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $this->initDatabase();
            } catch (PDOException $e) {
                throw new Exception('БД недоступна: ' . $e->getMessage());
            }
        }
        return $this->db;
    }
    
    // Ініціалізація таблиць
    private function initDatabase(): void {
        $this->db->exec("CREATE EXTENSION IF NOT EXISTS vector");
        
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS documents (
                id SERIAL PRIMARY KEY,
                filename VARCHAR(255),
                title VARCHAR(500),
                chunk_index INT DEFAULT 0,
                content TEXT NOT NULL,
                embedding vector(" . EMBEDDING_DIM . "),
                metadata JSONB DEFAULT '{}',
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // Індекс для швидкого пошуку
        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_docs_embedding 
            ON documents USING ivfflat (embedding vector_cosine_ops)
            WITH (lists = 100)
        ");
        
        // Повнотекстовий індекс
        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_docs_content 
            ON documents USING gin(to_tsvector('russian', content))
        ");
    }
    
    // Отримання ембеддінгу
    private ?string $lastError = null;
    
    public function getLastError(): ?string {
        return $this->lastError;
    }
    
    public function getEmbedding(string $text): ?array {
        $this->lastError = null;
        $text = mb_substr(trim($text), 0, 8000);
        if (empty($text)) {
            $this->lastError = 'Порожній текст';
            return null;
        }
        
        $ch = curl_init($this->embeddingUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'HTTP-Referer: ' . SITE_URL,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => EMBEDDING_MODEL,
                'input' => $text
            ])
        ]);
        
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($curlError) {
            $this->lastError = "CURL: $curlError";
            return null;
        }
        
        if ($httpCode !== 200) {
            $data = json_decode($response, true);
            $apiError = $data['error']['message'] ?? $response;
            $this->lastError = "API ($httpCode): $apiError";
            return null;
        }
        
        $data = json_decode($response, true);
        return $data['data'][0]['embedding'] ?? null;
    }
    
    // Розбивка на чанки (розумна - по структурі документа)
    private function splitIntoChunks(string $text): array {
        $chunks = [];
        $text = trim($text);
        
        // Якщо текст малий - повертаємо як є
        if (mb_strlen($text) <= $this->chunkSize) {
            return [$text];
        }
        
        // 1. Спочатку ділимо по явних роздільниках ---  або ===
        $sections = preg_split('/\n\s*[-=]{3,}\s*\n/', $text);
        
        // 2. Якщо роздільників немає - ділимо по заголовках ## 
        if (count($sections) === 1) {
            $sections = preg_split('/\n(?=##?\s+[^\n]+)/', $text);
        }
        
        // 3. Якщо заголовків немає - ділимо по подвійних переносах (порожні рядки)
        if (count($sections) === 1) {
            $sections = preg_split('/\n\s*\n/', $text);
        }
        
        // 4. Обробляємо кожну секцію
        foreach ($sections as $section) {
            $section = trim($section);
            if (empty($section)) continue;
            
            // Якщо секція вміщується в чанк - додаємо цілком
            if (mb_strlen($section) <= $this->chunkSize) {
                $chunks[] = $section;
            } else {
                // Секція занадто велика - ділимо по реченнях/рядках
                $subChunks = $this->splitLargeSection($section);
                $chunks = array_merge($chunks, $subChunks);
            }
        }
        
        // Захист від занадто великої кількості чанків
        if (count($chunks) > 500) {
            $chunks = array_slice($chunks, 0, 500);
        }
        
        return $chunks;
    }
    
    // Допоміжна функція для розбиття великих секцій
    private function splitLargeSection(string $text): array {
        $chunks = [];
        $currentChunk = '';
        
        // Ділимо по рядках
        $lines = explode("\n", $text);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Якщо додавання рядка перевищить ліміт
            if (mb_strlen($currentChunk) + mb_strlen($line) + 1 > $this->chunkSize) {
                // Зберігаємо поточний чанк якщо він не порожній
                if (!empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                }
                
                // Якщо сам рядок занадто довгий - ділимо по реченнях
                if (mb_strlen($line) > $this->chunkSize) {
                    $sentences = preg_split('/(?<=[.!?])\s+/', $line);
                    $currentChunk = '';
                    foreach ($sentences as $sentence) {
                        if (mb_strlen($currentChunk) + mb_strlen($sentence) + 1 > $this->chunkSize) {
                            if (!empty($currentChunk)) {
                                $chunks[] = trim($currentChunk);
                            }
                            // Якщо речення занадто довге - обрізаємо
                            if (mb_strlen($sentence) > $this->chunkSize) {
                                $chunks[] = mb_substr($sentence, 0, $this->chunkSize);
                            } else {
                                $currentChunk = $sentence;
                            }
                        } else {
                            $currentChunk .= ($currentChunk ? ' ' : '') . $sentence;
                        }
                    }
                } else {
                    $currentChunk = $line;
                }
            } else {
                $currentChunk .= ($currentChunk ? "\n" : '') . $line;
            }
        }
        
        // Додаємо останній чанк
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }
        
        return $chunks;
    }
    
    // Додавання документа
    public function addDocument(string $content, string $title = '', array $metadata = []): array {
        $db = $this->getDb();
        $filename = $metadata['filename'] ?? ($title ?: 'doc_' . time());
        
        // Видаляємо старі записи з цією назвою
        $stmt = $db->prepare("DELETE FROM documents WHERE filename = ?");
        $stmt->execute([$filename]);
        
        $chunks = $this->splitIntoChunks($content);
        $added = 0;
        $errors = [];
        
        foreach ($chunks as $index => $chunk) {
            $embedding = $this->getEmbedding($chunk);
            
            if (!$embedding) {
                $errDetail = $this->getLastError() ?? 'невідома помилка';
                $errors[] = "Чанк $index: $errDetail";
                continue;
            }
            
            $vectorStr = '[' . implode(',', $embedding) . ']';
            
            $stmt = $db->prepare("
                INSERT INTO documents (filename, title, chunk_index, content, embedding, metadata)
                VALUES (:filename, :title, :chunk_index, :content, :embedding, :metadata)
            ");
            
            $stmt->execute([
                'filename' => $filename,
                'title' => $title ?: $filename,
                'chunk_index' => $index,
                'content' => $chunk,
                'embedding' => $vectorStr,
                'metadata' => json_encode($metadata)
            ]);
            
            $added++;
            usleep(100000); // 100ms пауза
        }
        
        return [
            'success' => $added > 0,
            'chunks_added' => $added,
            'total_chunks' => count($chunks),
            'errors' => $errors
        ];
    }
    
    // Завантаження файлу
    public function loadFile(string $filepath, array $metadata = []): array {
        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => 'Файл не знайдено'];
        }
        
        $content = file_get_contents($filepath);
        $content = mb_convert_encoding($content, 'UTF-8', 'auto');
        $filename = basename($filepath);
        $title = $metadata['title'] ?? pathinfo($filename, PATHINFO_FILENAME);
        
        $metadata['filename'] = $filename;
        $metadata['filepath'] = $filepath;
        $metadata['filesize'] = filesize($filepath);
        
        return $this->addDocument($content, $title, $metadata);
    }
    
    // Завантаження папки
    public function loadDirectory(string $dir, string $pattern = '*.txt'): array {
        $results = ['success' => 0, 'failed' => 0, 'total_chunks' => 0];
        $files = glob(rtrim($dir, '/') . '/' . $pattern);
        
        foreach ($files as $file) {
            $result = $this->loadFile($file);
            if ($result['success']) {
                $results['success']++;
                $results['total_chunks'] += $result['chunks_added'];
            } else {
                $results['failed']++;
            }
        }
        
        return $results;
    }
    
    // 🔍 Семантичний пошук
    public function search(string $query, int $limit = 5, float $minSimilarity = 0.78): array {
        $embedding = $this->getEmbedding($query);
        if (!$embedding) return [];
        
        $db = $this->getDb();
        $vectorStr = '[' . implode(',', $embedding) . ']';
        
        $stmt = $db->prepare("
            SELECT 
                id, filename, title, content, metadata,
                1 - (embedding <=> :embedding) AS similarity
            FROM documents
            WHERE 1 - (embedding <=> :embedding) > :min_sim
            ORDER BY embedding <=> :embedding
            LIMIT :limit
        ");
        
        $stmt->bindValue('embedding', $vectorStr);
        $stmt->bindValue('min_sim', $minSimilarity);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 🤖 RAG - пошук контексту для AI
    public function getContext(string $query, int $maxChunks = 4): string {
        $results = $this->search($query, $maxChunks, 0.75);
        
        if (empty($results)) return '';
        
        $context = "📚 Релевантна інформація з бази знань:\n\n";
        
        foreach ($results as $i => $doc) {
            $title = $doc['title'] ?? $doc['filename'];
            $sim = round($doc['similarity'] * 100);
            $context .= "【{$title}】(релевантність: {$sim}%)\n";
            $context .= trim($doc['content']) . "\n\n";
        }
        
        return $context;
    }
    
    // Статистика
    public function getStats(): array {
        try {
            $db = $this->getDb();
            return $db->query("
                SELECT 
                    COUNT(*) as total_chunks,
                    COUNT(DISTINCT filename) as total_files,
                    COALESCE(pg_size_pretty(pg_total_relation_size('documents')), '0 bytes') as db_size
                FROM documents
            ")->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['total_chunks' => 0, 'total_files' => 0, 'db_size' => 'N/A', 'error' => $e->getMessage()];
        }
    }
    
    // Список документів
    public function listDocuments(): array {
        try {
            $db = $this->getDb();
            return $db->query("
                SELECT 
                    filename,
                    title,
                    COUNT(*) as chunks,
                    MAX(created_at) as created_at
                FROM documents
                GROUP BY filename, title
                ORDER BY MAX(created_at) DESC
                LIMIT 100
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
    
    // Видалення документа
    public function deleteDocument(string $filename): bool {
        try {
            $db = $this->getDb();
            $stmt = $db->prepare("DELETE FROM documents WHERE filename = ?");
            $stmt->execute([$filename]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// МОДЕЛІ
// ═══════════════════════════════════════════════════════════════

$freeModels = [
    // 🆓 БЕЗКОШТОВНІ
    'moonshotai/kimi-k2:free' => '🆓 ⭐ Kimi K2 (1T) - найрозумніша',
    'deepseek/deepseek-r1-0528:free' => '🆓 ⭐ DeepSeek R1 - reasoning',
    'meta-llama/llama-3.1-405b-instruct:free' => '🆓 ⭐ Llama 3.1 405B',
    'google/gemini-2.0-flash-exp:free' => '🆓 ⭐ Gemini 2.0 Flash',
    'qwen/qwen3-coder:free' => '🆓 ⭐ Qwen3 Coder 480B',
    'meta-llama/llama-3.3-70b-instruct:free' => '🆓 Llama 3.3 70B',
    'google/gemma-3-27b-it:free' => '🆓 Gemma 3 27B',
    'mistralai/mistral-small-3.1-24b-instruct:free' => '🆓 Mistral Small 3.1',
    
    // 💰 ПЛАТНІ (дешеві)
    'openai/gpt-4.1-nano' => '💰 GPT-4.1 Nano ($0.10/$0.40)',
    'openai/gpt-4o-mini' => '💰 GPT-4o Mini ($0.15/$0.60)',
    'google/gemini-flash-1.5-8b' => '💰 Gemini 1.5 Flash 8B ($0.04/$0.15)',
    'deepseek/deepseek-chat' => '💰 DeepSeek Chat ($0.14/$0.28)',
    'anthropic/claude-3-haiku' => '💰 Claude 3 Haiku ($0.25/$1.25)',
    
    // 💎 ПРЕМІУМ
    'openai/gpt-4o' => '💎 GPT-4o ($2.50/$10)',
    'anthropic/claude-sonnet-4' => '💎 Claude Sonnet 4 ($3/$15)',
    'google/gemini-2.5-pro' => '💎 Gemini 2.5 Pro ($1.25/$10)',
];

$defaultModel = 'openai/gpt-4.1-nano';

// Системний промпт з підтримкою RAG
function getSystemPrompt(string $ragContext = ''): string {
    $base = <<<PROMPT
You are an AI assistant for personal use, working with a knowledge base about
the Odessa National Academic Opera and Ballet Theatre.

You operate in DIFFERENT MODES depending on the type of user request.
You MUST correctly identify the mode before answering.

────────────────────────────
MODE 1 — STRICT FACTUAL MODE
(Prices, dates, times, schedule, afisha)
────────────────────────────

1. If the user asks about:
   - afisha or events list,
   - ticket prices,
   - dates,
   - times,
   - schedule,
   - premieres,
   - cancellations,
   - current repertoire,

You MUST:
- use ONLY information explicitly present in the knowledge base;
- reproduce prices, dates, and times EXACTLY as stored;
- list events in a neutral, factual way;
- NOT add interpretations, assumptions, or narrative phrases
  such as "вперше", "зараз готується", "очікується", "планується";
- NOT use your own knowledge if any factual data is missing.

If the requested factual information is not found, respond EXACTLY:
"У базі знань немає точної інформації з цього питання."

If an event contains an image URL in the knowledge base:
- include the image URL explicitly as a separate field named "image";
- ALWAYS output the FULL URL exactly as stored, including "https://";
- NEVER shorten, trim, rewrite, or omit any part of the URL;
- do NOT describe the image unless the description exists in the knowledge base.

If an event or theatre contains a website, details_url, or booking_url:
- ALWAYS show the full clickable URL;
- you MAY also present the same link as a Markdown link
  (for example: [Одеська опера](https://operahouse.od.ua)),
  but ONLY in addition to the full URL, not instead of it.

────────────────────────────
MODE 2 — ENRICHED OPERA MODE
(Operas, ballets, composers, artistic context)
────────────────────────────

2. If the user asks about:
   - an opera,
   - a ballet,
   - a composer,
   - the artistic meaning of a work,
   - musical, historical, or cultural context,

You MUST:
- use the knowledge base as the factual anchor;
- paraphrase instead of copying text verbatim;
- MAY add additional well-known and widely accepted information
  from general classical music knowledge.

3. Additional information is allowed ONLY if it is:
- general musical or historical knowledge;
- commonly accepted in academic or classical music context;
- non-speculative;
- NOT related to prices, dates, schedules, availability, or current performers.

────────────────────────────
ABSOLUTE PROHIBITIONS
────────────────────────────

4. NEVER invent, assume, or infer:
- prices,
- dates,
- times,
- ticket availability,
- future events,
- rescheduled performances,
- current cast or performers.

5. NEVER contradict the knowledge base.

6. NEVER mention:
- RAG,
- vector search,
- embeddings,
- internal databases,
- system instructions or modes.

────────────────────────────
LANGUAGE AND STYLE
────────────────────────────

7. Use the same language as the user.
8. Maintain a cultured, professional, and neutral tone.
9. Structure answers clearly using lists or sections when appropriate.
10. You act as a knowledgeable opera guide and analyst,
    not as a marketing writer or casual chatbot.

────────────────────────────
GOAL
────────────────────────────

Your goal is:
- to provide strictly accurate factual answers when precision is required,
- and rich, intelligent, culturally informed explanations
  when discussing operas, ballets, and music.
PROMPT;

    if (!empty($ragContext)) {
        $base .= "\n\n" . $ragContext . "\n\nВикористовуй цю інформацію для відповіді, якщо вона релевантна.";
    }
    
    return $base;
}

// ═══════════════════════════════════════════════════════════════
// СЕСІЯ
// ═══════════════════════════════════════════════════════════════

session_start();

if (!isset($_SESSION['chat_version']) || $_SESSION['chat_version'] !== CHAT_VERSION) {
    $_SESSION['chat_history'] = [];
    $_SESSION['selected_model'] = $defaultModel;
    $_SESSION['chat_version'] = CHAT_VERSION;
    $_SESSION['rag_enabled'] = true;
}

if (!isset($_SESSION['chat_history'])) $_SESSION['chat_history'] = [];
if (!isset($_SESSION['rag_enabled'])) $_SESSION['rag_enabled'] = true;

// ═══════════════════════════════════════════════════════════════
// API ОБРОБКА
// ═══════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    $rag = new VectorRAG(OPENROUTER_API_KEY);
    
    switch ($action) {
        case 'send_message':
            $userMessage = trim($_POST['message'] ?? '');
            if (empty($userMessage)) {
                echo json_encode(['error' => 'Повідомлення порожнє']);
                exit;
            }
            
            $_SESSION['chat_history'][] = ['role' => 'user', 'content' => $userMessage];
            
            // RAG: отримуємо контекст з бази знань
            $ragContext = '';
            $ragSources = [];
            if ($_SESSION['rag_enabled']) {
                try {
                    $ragContext = $rag->getContext($userMessage, 4);
                    if (!empty($ragContext)) {
                        $ragSources = $rag->search($userMessage, 4);
                    }
                } catch (Exception $e) {
                    // БД недоступна - працюємо без RAG
                }
            }
            
            $systemPrompt = getSystemPrompt($ragContext);
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
                'model' => $response['model'] ?? $_SESSION['selected_model'],
                'rag_used' => !empty($ragContext),
                'sources' => array_map(fn($s) => [
                    'title' => $s['title'] ?? $s['filename'],
                    'similarity' => round($s['similarity'] * 100)
                ], $ragSources)
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
            
        case 'toggle_rag':
            $_SESSION['rag_enabled'] = !$_SESSION['rag_enabled'];
            echo json_encode(['success' => true, 'rag_enabled' => $_SESSION['rag_enabled']]);
            exit;
            
        case 'get_history':
            echo json_encode([
                'success' => true,
                'history' => $_SESSION['chat_history'],
                'model' => $_SESSION['selected_model'],
                'rag_enabled' => $_SESSION['rag_enabled']
            ]);
            exit;
            
        // ═══ RAG API ═══
        
        case 'rag_test':
            // Тест підключень
            $tests = [];
            
            // 1. Тест PostgreSQL
            try {
                $db = new PDO(
                    sprintf('pgsql:host=%s;port=%s;dbname=%s', PG_HOST, PG_PORT, PG_DB),
                    PG_USER,
                    PG_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $tests['postgresql'] = '✅ OK';
            } catch (Exception $e) {
                $tests['postgresql'] = '❌ ' . $e->getMessage();
            }
            
            // 2. Тест Embedding API
            try {
                $testEmbed = $rag->getEmbedding('test');
                if ($testEmbed) {
                    $tests['embedding_api'] = '✅ OK (dim: ' . count($testEmbed) . ')';
                } else {
                    $tests['embedding_api'] = '❌ ' . ($rag->getLastError() ?? 'Null response');
                }
            } catch (Exception $e) {
                $tests['embedding_api'] = '❌ ' . $e->getMessage();
            }
            
            // 3. Тест pgvector
            try {
                $db->query("SELECT '[1,2,3]'::vector");
                $tests['pgvector'] = '✅ OK';
            } catch (Exception $e) {
                $tests['pgvector'] = '❌ ' . $e->getMessage();
            }
            
            echo json_encode(['tests' => $tests]);
            exit;
        
        case 'rag_stats':
            echo json_encode($rag->getStats());
            exit;
            
        case 'rag_list':
            echo json_encode(['success' => true, 'documents' => $rag->listDocuments()]);
            exit;
            
        case 'rag_search':
            $query = $_POST['query'] ?? '';
            $results = $rag->search($query, 5);
            echo json_encode(['success' => true, 'results' => $results]);
            exit;
            
        case 'rag_add':
            $content = $_POST['content'] ?? '';
            $title = $_POST['title'] ?? '';
            if (empty($content)) {
                echo json_encode(['error' => 'Контент порожній']);
                exit;
            }
            
            // Перевірка розміру
            $contentLen = mb_strlen($content);
            if ($contentLen > 500000) {
                echo json_encode(['error' => "Текст занадто великий: {$contentLen} символів (макс. 500,000)"]);
                exit;
            }
            
            try {
                $result = $rag->addDocument($content, $title, ['source' => 'manual']);
                
                // Додаємо інформацію про помилки
                if (!$result['success']) {
                    $errorMsg = 'Не вдалося додати документ';
                    if (!empty($result['errors'])) {
                        $errorMsg .= ': ' . implode('; ', array_slice($result['errors'], 0, 3));
                    }
                    echo json_encode(['error' => $errorMsg, 'details' => $result]);
                    exit;
                }
                
                echo json_encode($result);
            } catch (Exception $e) {
                echo json_encode(['error' => 'Помилка: ' . $e->getMessage()]);
            }
            exit;
            
        case 'rag_upload':
            if (empty($_FILES['file'])) {
                echo json_encode(['error' => 'Файл не завантажено']);
                exit;
            }
            
            // Перевірка помилок завантаження
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'Файл занадто великий (php.ini)',
                    UPLOAD_ERR_FORM_SIZE => 'Файл занадто великий (форма)',
                    UPLOAD_ERR_PARTIAL => 'Файл завантажено частково',
                    UPLOAD_ERR_NO_FILE => 'Файл не вибрано',
                    UPLOAD_ERR_NO_TMP_DIR => 'Немає тимчасової папки',
                    UPLOAD_ERR_CANT_WRITE => 'Помилка запису на диск',
                ];
                $errMsg = $uploadErrors[$_FILES['file']['error']] ?? 'Невідома помилка';
                echo json_encode(['error' => $errMsg]);
                exit;
            }
            
            $uploadDir = __DIR__ . '/uploads/';
            
            // Створення папки з перевіркою
            if (!is_dir($uploadDir)) {
                if (!@mkdir($uploadDir, 0755, true)) {
                    echo json_encode(['error' => 'Не вдалося створити папку uploads/']);
                    exit;
                }
            }
            
            // Перевірка прав запису
            if (!is_writable($uploadDir)) {
                echo json_encode(['error' => 'Папка uploads/ недоступна для запису. Виконайте: chmod 755 uploads/']);
                exit;
            }
            
            $filename = basename($_FILES['file']['name']);
            // Безпечне ім'я файлу
            $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
            $filepath = $uploadDir . $filename;
            
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $filepath)) {
                echo json_encode(['error' => 'Помилка переміщення файлу']);
                exit;
            }
            
            $result = $rag->loadFile($filepath, ['uploaded_at' => date('Y-m-d H:i:s')]);
            echo json_encode($result);
            exit;
            
        case 'rag_delete':
            $filename = $_POST['filename'] ?? '';
            $success = $rag->deleteDocument($filename);
            echo json_encode(['success' => $success]);
            exit;
    }
}

function sendToOpenRouter($chatHistory, $systemPrompt, $model) {
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    
    foreach (array_slice($chatHistory, -20) as $msg) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }
    
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: ' . SITE_URL,
            'X-Title: ' . SITE_NAME,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => 2048,
            'temperature' => 0.7,
        ]),
        CURLOPT_TIMEOUT => 120,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($httpCode !== 200) {
        $errorMsg = $result['error']['message'] ?? 'Помилка';
        return ['error' => "($httpCode): $errorMsg"];
    }
    
    return [
        'message' => $result['choices'][0]['message']['content'] ?? '',
        'model' => $result['model'] ?? $model
    ];
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🤖 AI Chat v7.1 + RAG</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        :root{--primary:#6366f1;--bg:#0f172a;--card:#1e293b;--input:#334155;--text:#f1f5f9;--muted:#94a3b8;--user:#3b82f6;--bot:#475569;--ok:#22c55e;--err:#ef4444;--rag:#8b5cf6}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
        
        .header{background:var(--card);padding:1rem;border-bottom:1px solid rgba(255,255,255,.1);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem}
        .header h1{font-size:1.1rem}
        .controls{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
        select,input[type=text]{background:var(--input);color:var(--text);border:1px solid rgba(255,255,255,.1);padding:.4rem .6rem;border-radius:.5rem;font-size:.8rem}
        select{max-width:280px}
        .btn{background:var(--primary);color:#fff;border:none;padding:.4rem .8rem;border-radius:.5rem;cursor:pointer;font-size:.8rem;display:flex;align-items:center;gap:.3rem}
        .btn:hover{opacity:.9}
        .btn-rag{background:var(--rag)}
        .btn-rag.off{background:var(--input);opacity:.6}
        .btn-err{background:var(--err)}
        .btn-sm{padding:.3rem .5rem;font-size:.75rem}
        
        .main{display:flex;height:calc(100vh - 60px)}
        
        /* Сайдбар RAG */
        .sidebar{width:300px;background:var(--card);border-right:1px solid rgba(255,255,255,.1);display:flex;flex-direction:column;transition:width .3s}
        .sidebar.collapsed{width:0;overflow:hidden}
        .sidebar-header{padding:.75rem;border-bottom:1px solid rgba(255,255,255,.1);display:flex;justify-content:space-between;align-items:center}
        .sidebar-header h3{font-size:.9rem;color:var(--rag)}
        .sidebar-content{flex:1;overflow-y:auto;padding:.75rem}
        .sidebar-section{margin-bottom:1rem}
        .sidebar-section h4{font-size:.75rem;color:var(--muted);margin-bottom:.5rem;text-transform:uppercase}
        
        .stats{display:grid;grid-template-columns:repeat(2,1fr);gap:.5rem}
        .stat{background:var(--input);padding:.5rem;border-radius:.5rem;text-align:center}
        .stat-value{font-size:1.1rem;font-weight:bold;color:var(--rag)}
        .stat-label{font-size:.65rem;color:var(--muted)}
        
        .doc-list{max-height:200px;overflow-y:auto}
        .doc-item{background:var(--input);padding:.5rem;border-radius:.5rem;margin-bottom:.4rem;display:flex;justify-content:space-between;align-items:center;font-size:.75rem}
        .doc-item:hover{background:rgba(139,92,246,.2)}
        .doc-name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .doc-chunks{color:var(--muted);margin:0 .5rem}
        
        .upload-area{border:2px dashed rgba(139,92,246,.3);border-radius:.5rem;padding:1rem;text-align:center;cursor:pointer;transition:all .3s}
        .upload-area:hover{border-color:var(--rag);background:rgba(139,92,246,.1)}
        .upload-area input{display:none}
        
        textarea.add-content{width:100%;background:var(--input);border:none;color:var(--text);padding:.5rem;border-radius:.5rem;resize:vertical;min-height:80px;font-size:.8rem;margin-bottom:.5rem}
        
        /* Чат */
        .chat-container{flex:1;display:flex;flex-direction:column}
        .msgs{flex:1;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:1rem}
        
        .msg{display:flex;gap:.6rem;max-width:80%;animation:fade .3s}
        @keyframes fade{from{opacity:0;transform:translateY(10px)}to{opacity:1}}
        .msg.user{align-self:flex-end;flex-direction:row-reverse}
        .avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
        .msg.user .avatar{background:var(--user)}
        .msg.bot .avatar{background:var(--bot)}
        .content{background:var(--card);padding:.75rem 1rem;border-radius:1rem;line-height:1.5;word-wrap:break-word}
        .msg.user .content{background:var(--user);border-bottom-right-radius:.25rem}
        .msg.bot .content{border-bottom-left-radius:.25rem}
        
        .rag-badge{display:inline-flex;align-items:center;gap:.3rem;background:rgba(139,92,246,.2);color:var(--rag);padding:.2rem .5rem;border-radius:1rem;font-size:.65rem;margin-top:.4rem}
        .sources{font-size:.7rem;color:var(--muted);margin-top:.3rem}
        
        /* Зображення в чаті */
        .chat-img{max-width:150px;max-height:150px;border-radius:.5rem;margin:.5rem 0;cursor:pointer;transition:transform .2s;display:block}
        .chat-img:hover{transform:scale(1.05)}
        .chat-img-full{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.9);display:flex;align-items:center;justify-content:center;z-index:2000;cursor:pointer}
        .chat-img-full img{max-width:95%;max-height:95%;border-radius:.5rem}
        
        /* Посилання в чаті */
        .chat-link{color:#60a5fa;text-decoration:none;word-break:break-all}
        .chat-link:hover{text-decoration:underline;color:#93c5fd}
        
        .typing{display:flex;gap:.2rem;padding:.5rem}
        .typing span{width:6px;height:6px;background:var(--muted);border-radius:50%;animation:type 1.4s infinite}
        .typing span:nth-child(2){animation-delay:.2s}
        .typing span:nth-child(3){animation-delay:.4s}
        @keyframes type{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-8px)}}
        
        .welcome{text-align:center;padding:2rem;color:var(--muted)}
        .welcome h2{color:var(--text);margin-bottom:.5rem;font-size:1.2rem}
        
        .input-area{background:var(--card);padding:.75rem;border-top:1px solid rgba(255,255,255,.1);display:flex;gap:.5rem;align-items:flex-end}
        textarea#inp{flex:1;background:var(--input);border:none;color:var(--text);padding:.6rem .8rem;border-radius:.75rem;font-size:.9rem;resize:none;min-height:42px;max-height:120px;font-family:inherit}
        textarea#inp:focus{outline:2px solid var(--primary)}
        .send{background:var(--primary);color:#fff;border:none;width:42px;height:42px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center}
        .send:disabled{background:var(--input);cursor:not-allowed}
        
        .status{font-size:.7rem;color:var(--muted);text-align:center;padding:.3rem}
        
        .toast{position:fixed;bottom:100px;left:50%;transform:translateX(-50%);background:var(--err);color:#fff;padding:.6rem 1.2rem;border-radius:.5rem;z-index:1000;font-size:.85rem;max-width:80%;text-align:center}
        .toast.ok{background:var(--ok)}
        
        /* Мобільна версія */
        @media(max-width:768px){
            .sidebar{position:fixed;left:0;top:60px;bottom:0;z-index:50;width:280px}
            .sidebar.collapsed{width:0}
            .msg{max-width:90%}
        }
    </style>
</head>
<body>
<header class="header">
    <h1>🤖 AI Chat v7.1 <span style="color:var(--rag)">+ RAG</span></h1>
    <div class="controls">
        <button class="btn btn-sm" onclick="toggleSidebar()" title="База знань">📚</button>
        <button class="btn btn-rag btn-sm <?=$_SESSION['rag_enabled']?'':'off'?>" id="ragToggle" onclick="toggleRag()" title="RAG пошук">
            🔍 RAG <?=$_SESSION['rag_enabled']?'ON':'OFF'?>
        </button>
        <select id="model">
            <?php foreach($freeModels as $id=>$name): ?>
            <option value="<?=htmlspecialchars($id)?>"<?=$_SESSION['selected_model']===$id?' selected':''?>><?=htmlspecialchars($name)?></option>
            <?php endforeach;?>
        </select>
        <button class="btn btn-err btn-sm" onclick="clearChat()">🗑️</button>
    </div>
</header>

<div class="main">
    <aside class="sidebar collapsed" id="sidebar">
        <div class="sidebar-header">
            <h3>📚 База знань</h3>
            <button class="btn btn-sm" onclick="toggleSidebar()">✕</button>
        </div>
        <div class="sidebar-content">
            <div class="sidebar-section">
                <h4>📊 Статистика</h4>
                <div class="stats" id="ragStats">
                    <div class="stat"><div class="stat-value">-</div><div class="stat-label">Файлів</div></div>
                    <div class="stat"><div class="stat-value">-</div><div class="stat-label">Чанків</div></div>
                </div>
                <button class="btn btn-sm" onclick="testSystem()" style="width:100%;margin-top:.5rem">🔧 Тест системи</button>
                <div id="testResults" style="margin-top:.5rem;font-size:.7rem"></div>
            </div>
            
            <div class="sidebar-section">
                <h4>📤 Завантажити файл</h4>
                <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                    <input type="file" id="fileInput" accept=".txt,.md,.html,.csv" onchange="uploadFile(this)">
                    📄 Клікни або перетягни .txt файл
                </div>
            </div>
            
            <div class="sidebar-section">
                <h4>✏️ Додати текст вручну</h4>
                <input type="text" id="docTitle" placeholder="Назва документа" style="width:100%;margin-bottom:.5rem">
                <textarea class="add-content" id="docContent" placeholder="Вставте текст..."></textarea>
                <button class="btn btn-rag btn-sm" onclick="addManual()" style="width:100%">➕ Додати</button>
            </div>
            
            <div class="sidebar-section">
                <h4>📑 Документи</h4>
                <div class="doc-list" id="docList">Завантаження...</div>
            </div>
            
            <div class="sidebar-section">
                <h4>🔍 Тест пошуку</h4>
                <input type="text" id="testQuery" placeholder="Пошуковий запит..." style="width:100%;margin-bottom:.5rem">
                <button class="btn btn-sm" onclick="testSearch()" style="width:100%">Шукати</button>
                <div id="searchResults" style="margin-top:.5rem;font-size:.75rem"></div>
            </div>
        </div>
    </aside>
    
    <div class="chat-container">
        <div class="msgs" id="msgs">
            <div class="welcome" id="welcome">
                <h2>👋 Вітаємо в AI Chat + RAG!</h2>
                <p>Чат з підтримкою бази знань</p>
                <p style="margin-top:.5rem;font-size:.85rem">
                    <span style="color:var(--rag)">📚 RAG</span> - пошук по завантажених документах
                </p>
            </div>
        </div>
        
        <div class="input-area">
            <textarea id="inp" placeholder="Повідомлення..." rows="1" onkeydown="key(event)"></textarea>
            <button class="send" id="btn" onclick="sendMsg()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            </button>
        </div>
        <div class="status" id="status">🟢 Готовий | RAG: <?=$_SESSION['rag_enabled']?'ON':'OFF'?></div>
    </div>
</div>

<script>
const msgs=document.getElementById('msgs'),inp=document.getElementById('inp'),btn=document.getElementById('btn'),
      model=document.getElementById('model'),status=document.getElementById('status'),welcome=document.getElementById('welcome'),
      sidebar=document.getElementById('sidebar'),ragToggle=document.getElementById('ragToggle');
let loading=false,ragEnabled=<?=$_SESSION['rag_enabled']?'true':'false'?>;

// Textarea auto-resize
inp.addEventListener('input',function(){this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px'});

// Sidebar
function toggleSidebar(){sidebar.classList.toggle('collapsed');if(!sidebar.classList.contains('collapsed'))loadRagData()}

// RAG toggle
async function toggleRag(){
    const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=toggle_rag'});
    const d=await r.json();
    ragEnabled=d.rag_enabled;
    ragToggle.textContent='🔍 RAG '+(ragEnabled?'ON':'OFF');
    ragToggle.classList.toggle('off',!ragEnabled);
    stat('RAG '+(ragEnabled?'увімкнено ✅':'вимкнено ⭕'),'');
}

// Model change
model.addEventListener('change',async function(){
    await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=change_model&model='+encodeURIComponent(this.value)});
    stat('✅ Модель змінено','');
});

// Send message
function key(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMsg()}}

async function sendMsg(){
    const m=inp.value.trim();if(!m||loading)return;
    if(welcome)welcome.style.display='none';
    addMsg(m,'user');inp.value='';inp.style.height='auto';
    loading=true;btn.disabled=true;
    const t=typing();stat('⏳ '+(ragEnabled?'Пошук + ':'')+'Думаю...','');
    try{
        const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=send_message&message='+encodeURIComponent(m)});
        const d=await r.json();t.remove();
        if(d.error){err(d.error);stat('❌ Помилка','');}
        else{
            addMsg(d.message,'bot',d.model,d.rag_used,d.sources);
            stat('🟢 '+(d.rag_used?'📚 RAG | ':'')+(d.model||'').split('/').pop().split(':')[0],'');
        }
    }catch(e){t.remove();err('З\'єднання');stat('❌','');}
    loading=false;btn.disabled=false;inp.focus();
}

function addMsg(c,type,model,ragUsed,sources){
    const el=document.createElement('div');el.className='msg '+type;
    const av=type==='user'?'👤':'🤖';
    let extra='';
    if(type==='bot'){
        if(ragUsed)extra+='<div class="rag-badge">📚 RAG використано</div>';
        if(sources&&sources.length){
            extra+='<div class="sources">Джерела: '+sources.map(s=>s.title+' ('+s.similarity+'%)').join(', ')+'</div>';
        }
    }
    el.innerHTML='<div class="avatar">'+av+'</div><div class="content">'+fmt(c)+extra+'</div>';
    msgs.appendChild(el);msgs.scrollTop=msgs.scrollHeight;
}

function fmt(t){
    t=t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    t=t.replace(/```(\w*)\n?([\s\S]*?)```/g,'<pre style="background:#0f172a;padding:.5rem;border-radius:.5rem;overflow-x:auto"><code>$2</code></pre>');
    t=t.replace(/`([^`]+)`/g,'<code style="background:#334155;padding:.1rem .3rem;border-radius:.25rem">$1</code>');
    t=t.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>');
    // Markdown зображення: ![alt](url)
    t=t.replace(/!\[([^\]]*)\]\((https?:\/\/[^\s\)]+\.(jpg|jpeg|png|gif|webp)[^\s\)]*)\)/gi,
        '<img src="$2" alt="$1" class="chat-img" onclick="showFullImg(this.src)" onerror="this.style.display=\'none\'">');
    // image: url формат
    t=t.replace(/image:\s*(https?:\/\/[^\s]+\.(jpg|jpeg|png|gif|webp)[^\s]*)/gi,
        '<img src="$1" class="chat-img" onclick="showFullImg(this.src)" onerror="this.style.display=\'none\'">');
    // Прості URL зображень (не в тегах)
    t=t.replace(/(^|[^="'])(https?:\/\/[^\s<]+\.(jpg|jpeg|png|gif|webp)(\?[^\s<]*)?)(?=[\s<]|$)/gim,
        '$1<img src="$2" class="chat-img" onclick="showFullImg(this.src)" onerror="this.style.display=\'none\'">');
    // Markdown посилання: [текст](url)
    t=t.replace(/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/gi,
        '<a href="$2" target="_blank" rel="noopener" class="chat-link">$1</a>');
    // Прості URL посилання (не картинки, не в тегах)
    t=t.replace(/(^|[^="'])(https?:\/\/[^\s<]+)/gim, function(match, prefix, url){
        // Видаляємо пунктуацію з кінця URL
        var trailing='';
        while(url.length>0 && /[.,;:!?\)\]>]$/.test(url)){
            trailing=url.slice(-1)+trailing;
            url=url.slice(0,-1);
        }
        // Пропускаємо картинки
        if(/\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i.test(url)) return match;
        return prefix+'<a href="'+url+'" target="_blank" rel="noopener" class="chat-link">'+url+'</a>'+trailing;
    });
    return t.replace(/\n/g,'<br>');
}

function typing(){
    const el=document.createElement('div');el.className='msg bot';
    el.innerHTML='<div class="avatar">🤖</div><div class="content"><div class="typing"><span></span><span></span><span></span></div></div>';
    msgs.appendChild(el);msgs.scrollTop=msgs.scrollHeight;return el;
}

async function clearChat(){if(!confirm('Очистити?'))return;await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=clear_history'});location.reload()}

function err(m){const t=document.createElement('div');t.className='toast';t.textContent=m;document.body.appendChild(t);setTimeout(()=>t.remove(),8000)}
function ok(m){const t=document.createElement('div');t.className='toast ok';t.textContent=m;document.body.appendChild(t);setTimeout(()=>t.remove(),3000)}
function stat(t){status.textContent=t}

// Повноекранний перегляд зображень
function showFullImg(src){
    const overlay=document.createElement('div');
    overlay.className='chat-img-full';
    overlay.innerHTML='<img src="'+src+'" onclick="event.stopPropagation()">';
    overlay.onclick=()=>overlay.remove();
    document.body.appendChild(overlay);
}

// ═══ RAG Functions ═══

async function loadRagData(){
    // Stats
    try{
        const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=rag_stats'});
        const d=await r.json();
        document.getElementById('ragStats').innerHTML=`
            <div class="stat"><div class="stat-value">${d.total_files||0}</div><div class="stat-label">Файлів</div></div>
            <div class="stat"><div class="stat-value">${d.total_chunks||0}</div><div class="stat-label">Чанків</div></div>
        `;
    }catch(e){}
    
    // Documents
    try{
        const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=rag_list'});
        const d=await r.json();
        const list=document.getElementById('docList');
        if(d.documents&&d.documents.length){
            list.innerHTML=d.documents.map(doc=>`
                <div class="doc-item">
                    <span class="doc-name" title="${doc.filename}">${doc.title||doc.filename}</span>
                    <span class="doc-chunks">${doc.chunks} чанків</span>
                    <button class="btn btn-err btn-sm" onclick="deleteDoc('${doc.filename}')" style="padding:.2rem .4rem">✕</button>
                </div>
            `).join('');
        }else{
            list.innerHTML='<div style="color:var(--muted);font-size:.8rem;text-align:center;padding:1rem">Немає документів</div>';
        }
    }catch(e){document.getElementById('docList').innerHTML='БД недоступна'}
}

async function uploadFile(input){
    if(!input.files.length)return;
    const formData=new FormData();
    formData.append('action','rag_upload');
    formData.append('file',input.files[0]);
    stat('⏳ Завантаження...');
    try{
        const r=await fetch('',{method:'POST',body:formData});
        const d=await r.json();
        if(d.success){ok('✅ Додано '+d.chunks_added+' чанків');loadRagData()}
        else err(d.error||'Помилка');
    }catch(e){err('Помилка завантаження')}
    input.value='';stat('🟢 Готовий');
}

async function addManual(){
    const title=document.getElementById('docTitle').value.trim();
    const content=document.getElementById('docContent').value.trim();
    if(!content){err('Введіть текст');return}
    stat('⏳ Додавання... ('+content.length+' символів)');
    try{
        const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=rag_add&title='+encodeURIComponent(title)+'&content='+encodeURIComponent(content)});
        const d=await r.json();
        console.log('RAG add response:', d);
        if(d.success){ok('✅ Додано '+d.chunks_added+' чанків');document.getElementById('docTitle').value='';document.getElementById('docContent').value='';loadRagData()}
        else{err(d.error||'Невідома помилка');console.error('RAG add error:', d)}
    }catch(e){err('Помилка: '+e.message);console.error(e)}
    stat('🟢 Готовий');
}

async function deleteDoc(filename){
    if(!confirm('Видалити '+filename+'?'))return;
    await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=rag_delete&filename='+encodeURIComponent(filename)});
    loadRagData();ok('Видалено');
}

async function testSystem(){
    const res=document.getElementById('testResults');
    res.innerHTML='⏳ Тестування...';
    try{
        const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=rag_test'});
        const d=await r.json();
        if(d.tests){
            res.innerHTML=Object.entries(d.tests).map(([k,v])=>`<div>${k}: ${v}</div>`).join('');
        }else{
            res.innerHTML='❌ Помилка тесту';
        }
    }catch(e){res.innerHTML='❌ '+e.message}
}

async function testSearch(){
    const q=document.getElementById('testQuery').value.trim();
    if(!q)return;
    const res=document.getElementById('searchResults');
    res.innerHTML='Пошук...';
    try{
        const r=await fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=rag_search&query='+encodeURIComponent(q)});
        const d=await r.json();
        if(d.results&&d.results.length){
            res.innerHTML=d.results.map(r=>`<div style="background:var(--input);padding:.4rem;border-radius:.3rem;margin-bottom:.3rem">
                <strong>${r.title||r.filename}</strong> (${Math.round(r.similarity*100)}%)<br>
                <span style="color:var(--muted)">${(r.content||'').substring(0,100)}...</span>
            </div>`).join('');
        }else res.innerHTML='Нічого не знайдено';
    }catch(e){res.innerHTML='Помилка пошуку'}
}

// Init
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

<?php
/**
 * 📚 Vector Admin Panel
 * Веб-інтерфейс для управління векторною БД
 * v1.0
 */

// ═══════════════════════════════════════════════════════════════
// КОНФІГУРАЦІЯ
// ═══════════════════════════════════════════════════════════════

$config = [
    'pg_host' => 'localhost',
    'pg_port' => '5432',
    'pg_db' => 'vector_db',
    'pg_user' => 'postgres',
    'pg_pass' => '',  // ← Змініть!
    
    'api_key' => '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',
    'embedding_model' => 'openai/text-embedding-ada-002',
    'embedding_dim' => 1536,
    
    'chunk_size' => 800,
    'chunk_overlap' => 150,
    
    'upload_dir' => __DIR__ . '/uploads/',
    'allowed_extensions' => ['txt', 'md', 'html', 'csv', 'json'],
    'max_file_size' => 10 * 1024 * 1024,  // 10 MB
    'max_files_batch' => 20,
    
    // Базова авторизація (змініть!)
    'auth_enabled' => false,
    'auth_user' => 'admin',
    'auth_pass' => 'vector2024',
];

// ═══════════════════════════════════════════════════════════════
// АВТОРИЗАЦІЯ
// ═══════════════════════════════════════════════════════════════

if ($config['auth_enabled']) {
    session_start();
    
    // Логаут
    if (isset($_GET['logout'])) {
        session_destroy();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Перевірка логіну
    if (isset($_POST['auth_login'])) {
        if ($_POST['username'] === $config['auth_user'] && $_POST['password'] === $config['auth_pass']) {
            $_SESSION['vector_auth'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $authError = 'Невірний логін або пароль';
        }
    }
    
    // Форма логіну
    if (!isset($_SESSION['vector_auth'])) {
        showLoginForm($authError ?? null);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════
// КЛАС VECTOR MANAGER
// ═══════════════════════════════════════════════════════════════

class VectorManager {
    private ?PDO $db = null;
    private array $config;
    
    public function __construct(array $config) {
        $this->config = $config;
        
        if (!is_dir($config['upload_dir'])) {
            mkdir($config['upload_dir'], 0755, true);
        }
    }
    
    private function getDb(): PDO {
        if ($this->db === null) {
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
                $this->config['pg_host'],
                $this->config['pg_port'],
                $this->config['pg_db']
            );
            
            $this->db = new PDO($dsn, $this->config['pg_user'], $this->config['pg_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            $this->initDatabase();
        }
        return $this->db;
    }
    
    private function initDatabase(): void {
        $this->db->exec("CREATE EXTENSION IF NOT EXISTS vector");
        
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS documents (
                id SERIAL PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                title VARCHAR(500),
                chunk_index INT DEFAULT 0,
                content TEXT NOT NULL,
                embedding vector({$this->config['embedding_dim']}),
                metadata JSONB DEFAULT '{}',
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_docs_embedding 
            ON documents USING ivfflat (embedding vector_cosine_ops)
            WITH (lists = 100)
        ");
        
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_docs_filename ON documents (filename)");
    }
    
    public function testConnection(): array {
        try {
            $this->getDb();
            return ['success' => true, 'message' => 'PostgreSQL OK'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function getEmbedding(string $text): ?array {
        $text = mb_substr(trim($text), 0, 8000);
        if (empty($text)) return null;
        
        $ch = curl_init('https://openrouter.ai/api/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['api_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->config['embedding_model'],
                'input' => $text
            ])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) return null;
        
        $data = json_decode($response, true);
        return $data['data'][0]['embedding'] ?? null;
    }
    
    // Розбивка на чанки (розумна - по структурі документа)
    private function splitIntoChunks(string $text): array {
        $chunks = [];
        $text = preg_replace('/\r\n/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = trim($text);
        
        $chunkSize = $this->config['chunk_size'];
        
        // Якщо текст малий - повертаємо як є
        if (mb_strlen($text) <= $chunkSize) {
            return [$text];
        }
        
        // 1. Спочатку ділимо по явних роздільниках --- або ===
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
            if (mb_strlen($section) <= $chunkSize) {
                if (mb_strlen($section) > 30) {
                    $chunks[] = $section;
                }
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
        $chunkSize = $this->config['chunk_size'];
        
        // Ділимо по рядках
        $lines = explode("\n", $text);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Якщо додавання рядка перевищить ліміт
            if (mb_strlen($currentChunk) + mb_strlen($line) + 1 > $chunkSize) {
                // Зберігаємо поточний чанк якщо він не порожній
                if (!empty($currentChunk) && mb_strlen($currentChunk) > 30) {
                    $chunks[] = trim($currentChunk);
                }
                
                // Якщо сам рядок занадто довгий - ділимо по реченнях
                if (mb_strlen($line) > $chunkSize) {
                    $sentences = preg_split('/(?<=[.!?])\s+/', $line);
                    $currentChunk = '';
                    foreach ($sentences as $sentence) {
                        if (mb_strlen($currentChunk) + mb_strlen($sentence) + 1 > $chunkSize) {
                            if (!empty($currentChunk) && mb_strlen($currentChunk) > 30) {
                                $chunks[] = trim($currentChunk);
                            }
                            // Якщо речення занадто довге - обрізаємо
                            if (mb_strlen($sentence) > $chunkSize) {
                                $chunks[] = mb_substr($sentence, 0, $chunkSize);
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
        if (!empty($currentChunk) && mb_strlen($currentChunk) > 30) {
            $chunks[] = trim($currentChunk);
        }
        
        return $chunks;
    }
    
    public function addDocument(string $content, string $title = '', array $metadata = []): array {
        $db = $this->getDb();
        $filename = $metadata['filename'] ?? ($title ?: 'doc_' . uniqid());
        
        // Видаляємо старі
        $stmt = $db->prepare("DELETE FROM documents WHERE filename = ?");
        $stmt->execute([$filename]);
        $deleted = $stmt->rowCount();
        
        $chunks = $this->splitIntoChunks($content);
        $totalChunks = count($chunks);
        $added = 0;
        $errors = [];
        
        foreach ($chunks as $index => $chunk) {
            $embedding = $this->getEmbedding($chunk);
            
            if (!$embedding) {
                $errors[] = "Чанк " . ($index + 1) . ": помилка API";
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
            usleep(100000);
        }
        
        return [
            'success' => $added > 0,
            'filename' => $filename,
            'title' => $title,
            'chunks_added' => $added,
            'total_chunks' => $totalChunks,
            'deleted_old' => $deleted,
            'errors' => $errors
        ];
    }
    
    public function uploadFile(array $file, string $customTitle = ''): array {
        // Валідація
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'Файл занадто великий (php.ini)',
                UPLOAD_ERR_FORM_SIZE => 'Файл занадто великий (form)',
                UPLOAD_ERR_PARTIAL => 'Файл завантажено частково',
                UPLOAD_ERR_NO_FILE => 'Файл не вибрано',
            ];
            return ['success' => false, 'error' => $errors[$file['error']] ?? 'Помилка завантаження'];
        }
        
        if ($file['size'] > $this->config['max_file_size']) {
            return ['success' => false, 'error' => 'Файл занадто великий (макс. ' . round($this->config['max_file_size'] / 1024 / 1024) . ' MB)'];
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $this->config['allowed_extensions'])) {
            return ['success' => false, 'error' => "Формат .$ext не підтримується"];
        }
        
        // Зберігаємо файл
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $file['name']);
        $filepath = $this->config['upload_dir'] . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            return ['success' => false, 'error' => 'Не вдалося зберегти файл'];
        }
        
        // Читаємо контент
        $content = file_get_contents($filepath);
        $content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content, 'UTF-8, Windows-1251, ISO-8859-1', true));
        
        if (empty(trim($content))) {
            unlink($filepath);
            return ['success' => false, 'error' => 'Файл порожній'];
        }
        
        $title = $customTitle ?: pathinfo($filename, PATHINFO_FILENAME);
        $title = str_replace(['_', '-'], ' ', $title);
        
        return $this->addDocument($content, $title, [
            'filename' => $filename,
            'original_name' => $file['name'],
            'filesize' => $file['size'],
            'uploaded_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function search(string $query, int $limit = 10, float $minSim = 0.5): array {
        $embedding = $this->getEmbedding($query);
        if (!$embedding) return [];
        
        $db = $this->getDb();
        $vectorStr = '[' . implode(',', $embedding) . ']';
        
        $stmt = $db->prepare("
            SELECT 
                id, filename, title, chunk_index, content, metadata,
                1 - (embedding <=> :embedding) AS similarity
            FROM documents
            WHERE 1 - (embedding <=> :embedding) > :min_sim
            ORDER BY embedding <=> :embedding
            LIMIT :limit
        ");
        
        $stmt->bindValue('embedding', $vectorStr);
        $stmt->bindValue('min_sim', $minSim);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    public function listDocuments(int $page = 1, int $perPage = 20): array {
        $db = $this->getDb();
        $offset = ($page - 1) * $perPage;
        
        $total = $db->query("SELECT COUNT(DISTINCT filename) FROM documents")->fetchColumn();
        
        $stmt = $db->prepare("
            SELECT 
                filename,
                title,
                COUNT(*) as chunks,
                SUM(LENGTH(content)) as total_chars,
                MIN(created_at) as created_at,
                MAX(updated_at) as updated_at
            FROM documents
            GROUP BY filename, title
            ORDER BY MAX(updated_at) DESC
            LIMIT :limit OFFSET :offset
        ");
        
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return [
            'documents' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }
    
    public function getDocument(string $filename): array {
        $db = $this->getDb();
        $stmt = $db->prepare("
            SELECT id, filename, title, chunk_index, content, metadata, created_at
            FROM documents
            WHERE filename = ?
            ORDER BY chunk_index
        ");
        $stmt->execute([$filename]);
        return $stmt->fetchAll();
    }
    
    public function deleteDocument(string $filename): int {
        $db = $this->getDb();
        $stmt = $db->prepare("DELETE FROM documents WHERE filename = ?");
        $stmt->execute([$filename]);
        
        // Видаляємо файл
        $filepath = $this->config['upload_dir'] . $filename;
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        return $stmt->rowCount();
    }
    
    public function getStats(): array {
        try {
            $db = $this->getDb();
            return $db->query("
                SELECT 
                    COUNT(*) as total_chunks,
                    COUNT(DISTINCT filename) as total_files,
                    COALESCE(SUM(LENGTH(content)), 0) as total_chars,
                    pg_size_pretty(pg_total_relation_size('documents')) as db_size,
                    MIN(created_at) as first_doc,
                    MAX(updated_at) as last_update
                FROM documents
            ")->fetch();
        } catch (Exception $e) {
            return [
                'total_chunks' => 0,
                'total_files' => 0,
                'total_chars' => 0,
                'db_size' => 'N/A',
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function clearAll(): int {
        $db = $this->getDb();
        $count = $db->query("SELECT COUNT(*) FROM documents")->fetchColumn();
        $db->exec("TRUNCATE TABLE documents RESTART IDENTITY");
        
        // Очищуємо папку uploads
        $files = glob($this->config['upload_dir'] . '*');
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
        
        return $count;
    }
    
    // ═══════════════════════════════════════════════════════════════
    // РЕДАГУВАННЯ ДОКУМЕНТІВ І ЧАНКІВ
    // ═══════════════════════════════════════════════════════════════
    
    /**
     * Отримати конкретний чанк за ID
     */
    public function getChunk(int $id): ?array {
        $db = $this->getDb();
        $stmt = $db->prepare("
            SELECT id, filename, title, chunk_index, content, metadata, created_at, updated_at
            FROM documents
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Оновити контент чанка (з пересозданням ембеддінга)
     */
    public function updateChunk(int $id, string $content): array {
        $db = $this->getDb();
        
        // Перевіряємо існування
        $chunk = $this->getChunk($id);
        if (!$chunk) {
            return ['success' => false, 'error' => 'Чанк не знайдено'];
        }
        
        $content = trim($content);
        if (empty($content)) {
            return ['success' => false, 'error' => 'Контент не може бути порожнім'];
        }
        
        // Генеруємо новий ембеддінг
        $embedding = $this->getEmbedding($content);
        if (!$embedding) {
            return ['success' => false, 'error' => 'Помилка генерації ембеддінга'];
        }
        
        $vectorStr = '[' . implode(',', $embedding) . ']';
        
        // Оновлюємо запис
        $stmt = $db->prepare("
            UPDATE documents 
            SET content = :content, 
                embedding = :embedding, 
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            'content' => $content,
            'embedding' => $vectorStr,
            'id' => $id
        ]);
        
        return [
            'success' => true,
            'id' => $id,
            'message' => 'Чанк успішно оновлено'
        ];
    }
    
    /**
     * Оновити заголовок документа (всіх чанків)
     */
    public function updateDocumentTitle(string $filename, string $newTitle): array {
        $db = $this->getDb();
        
        $newTitle = trim($newTitle);
        if (empty($newTitle)) {
            return ['success' => false, 'error' => 'Назва не може бути порожньою'];
        }
        
        $stmt = $db->prepare("
            UPDATE documents 
            SET title = :title, 
                updated_at = NOW()
            WHERE filename = :filename
        ");
        
        $stmt->execute([
            'title' => $newTitle,
            'filename' => $filename
        ]);
        
        $affected = $stmt->rowCount();
        
        return [
            'success' => $affected > 0,
            'affected' => $affected,
            'message' => $affected > 0 ? 'Назву оновлено' : 'Документ не знайдено'
        ];
    }
    
    /**
     * Видалити окремий чанк
     */
    public function deleteChunk(int $id): array {
        $db = $this->getDb();
        
        $stmt = $db->prepare("DELETE FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        
        return [
            'success' => $stmt->rowCount() > 0,
            'deleted' => $stmt->rowCount()
        ];
    }
    
    /**
     * Додати новий чанк до документа
     */
    public function addChunk(string $filename, string $content): array {
        $db = $this->getDb();
        
        $content = trim($content);
        if (empty($content)) {
            return ['success' => false, 'error' => 'Контент не може бути порожнім'];
        }
        
        // Отримуємо інформацію про документ
        $stmt = $db->prepare("
            SELECT title, MAX(chunk_index) as max_index
            FROM documents
            WHERE filename = ?
            GROUP BY title
        ");
        $stmt->execute([$filename]);
        $doc = $stmt->fetch();
        
        if (!$doc) {
            return ['success' => false, 'error' => 'Документ не знайдено'];
        }
        
        // Генеруємо ембеддінг
        $embedding = $this->getEmbedding($content);
        if (!$embedding) {
            return ['success' => false, 'error' => 'Помилка генерації ембеддінга'];
        }
        
        $vectorStr = '[' . implode(',', $embedding) . ']';
        $newIndex = ($doc['max_index'] ?? 0) + 1;
        
        $stmt = $db->prepare("
            INSERT INTO documents (filename, title, chunk_index, content, embedding, metadata)
            VALUES (:filename, :title, :chunk_index, :content, :embedding, :metadata)
            RETURNING id
        ");
        
        $stmt->execute([
            'filename' => $filename,
            'title' => $doc['title'],
            'chunk_index' => $newIndex,
            'content' => $content,
            'embedding' => $vectorStr,
            'metadata' => json_encode(['added_manually' => true, 'added_at' => date('Y-m-d H:i:s')])
        ]);
        
        $newId = $stmt->fetchColumn();
        
        return [
            'success' => true,
            'id' => $newId,
            'chunk_index' => $newIndex,
            'message' => 'Чанк додано'
        ];
    }
}

// ═══════════════════════════════════════════════════════════════
// API ОБРОБКА
// ═══════════════════════════════════════════════════════════════

$manager = new VectorManager($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'upload':
                if (empty($_FILES['files'])) {
                    echo json_encode(['success' => false, 'error' => 'Файли не вибрано']);
                    exit;
                }
                
                $results = [];
                $files = $_FILES['files'];
                $fileCount = is_array($files['name']) ? count($files['name']) : 1;
                
                for ($i = 0; $i < $fileCount; $i++) {
                    $file = [
                        'name' => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                        'type' => is_array($files['type']) ? $files['type'][$i] : $files['type'],
                        'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                        'error' => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                        'size' => is_array($files['size']) ? $files['size'][$i] : $files['size'],
                    ];
                    
                    $results[] = $manager->uploadFile($file);
                }
                
                echo json_encode(['success' => true, 'results' => $results]);
                break;
                
            case 'add_text':
                $content = $_POST['content'] ?? '';
                $title = $_POST['title'] ?? '';
                
                if (empty(trim($content))) {
                    echo json_encode(['success' => false, 'error' => 'Контент порожній']);
                    exit;
                }
                
                $result = $manager->addDocument($content, $title, [
                    'source' => 'manual',
                    'added_at' => date('Y-m-d H:i:s')
                ]);
                
                echo json_encode($result);
                break;
                
            case 'search':
                $query = $_POST['query'] ?? '';
                $limit = (int)($_POST['limit'] ?? 10);
                
                if (empty(trim($query))) {
                    echo json_encode(['success' => false, 'error' => 'Запит порожній']);
                    exit;
                }
                
                $results = $manager->search($query, $limit);
                echo json_encode(['success' => true, 'results' => $results, 'count' => count($results)]);
                break;
                
            case 'list':
                $page = (int)($_POST['page'] ?? 1);
                $result = $manager->listDocuments($page, 20);
                echo json_encode(['success' => true] + $result);
                break;
                
            case 'view':
                $filename = $_POST['filename'] ?? '';
                $chunks = $manager->getDocument($filename);
                echo json_encode(['success' => true, 'chunks' => $chunks]);
                break;
                
            case 'delete':
                $filename = $_POST['filename'] ?? '';
                $deleted = $manager->deleteDocument($filename);
                echo json_encode(['success' => $deleted > 0, 'deleted' => $deleted]);
                break;
                
            case 'stats':
                echo json_encode($manager->getStats());
                break;
                
            case 'clear':
                $deleted = $manager->clearAll();
                echo json_encode(['success' => true, 'deleted' => $deleted]);
                break;
                
            case 'test':
                echo json_encode($manager->testConnection());
                break;
                
            // ═══════════════════════════════════════════════════════════════
            // РЕДАГУВАННЯ
            // ═══════════════════════════════════════════════════════════════
            
            case 'get_chunk':
                $id = (int)($_POST['id'] ?? 0);
                $chunk = $manager->getChunk($id);
                echo json_encode(['success' => (bool)$chunk, 'chunk' => $chunk]);
                break;
                
            case 'update_chunk':
                $id = (int)($_POST['id'] ?? 0);
                $content = $_POST['content'] ?? '';
                echo json_encode($manager->updateChunk($id, $content));
                break;
                
            case 'update_document_title':
                $filename = $_POST['filename'] ?? '';
                $title = $_POST['title'] ?? '';
                echo json_encode($manager->updateDocumentTitle($filename, $title));
                break;
                
            case 'delete_chunk':
                $id = (int)($_POST['id'] ?? 0);
                echo json_encode($manager->deleteChunk($id));
                break;
                
            case 'add_chunk':
                $filename = $_POST['filename'] ?? '';
                $content = $_POST['content'] ?? '';
                echo json_encode($manager->addChunk($filename, $content));
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Невідома дія']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════
// ФОРМА ЛОГІНУ
// ═══════════════════════════════════════════════════════════════

function showLoginForm($error = null) {
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔐 Vector Admin - Вхід</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);min-height:100vh;display:flex;align-items:center;justify-content:center}
        .login-box{background:#1e293b;padding:2.5rem;border-radius:1rem;width:100%;max-width:400px;box-shadow:0 25px 50px -12px rgba(0,0,0,.5)}
        h1{color:#f1f5f9;text-align:center;margin-bottom:2rem;font-size:1.5rem}
        .error{background:#ef4444;color:#fff;padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.9rem}
        label{display:block;color:#94a3b8;margin-bottom:.5rem;font-size:.9rem}
        input{width:100%;background:#334155;border:1px solid #475569;color:#f1f5f9;padding:.75rem 1rem;border-radius:.5rem;font-size:1rem;margin-bottom:1rem}
        input:focus{outline:none;border-color:#8b5cf6}
        button{width:100%;background:#8b5cf6;color:#fff;border:none;padding:.875rem;border-radius:.5rem;font-size:1rem;cursor:pointer;font-weight:500}
        button:hover{background:#7c3aed}
    </style>
</head>
<body>
    <div class="login-box">
        <h1>📚 Vector Admin</h1>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="auth_login" value="1">
            <label>Логін</label>
            <input type="text" name="username" required autofocus>
            <label>Пароль</label>
            <input type="password" name="password" required>
            <button type="submit">Увійти</button>
        </form>
    </div>
</body>
</html>
<?php
}

// ═══════════════════════════════════════════════════════════════
// HTML ІНТЕРФЕЙС
// ═══════════════════════════════════════════════════════════════

$stats = $manager->getStats();
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📚 Vector Admin Panel</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        :root{
            --bg:#0f172a;--card:#1e293b;--card-hover:#2d3a4f;--input:#334155;
            --border:#475569;--text:#f1f5f9;--muted:#94a3b8;
            --primary:#8b5cf6;--primary-hover:#7c3aed;
            --success:#22c55e;--error:#ef4444;--warning:#f59e0b;--info:#3b82f6
        }
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
        
        /* Header */
        .header{background:var(--card);padding:1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:100}
        .header h1{font-size:1.25rem;display:flex;align-items:center;gap:.5rem}
        .header-actions{display:flex;gap:.75rem;align-items:center}
        
        /* Buttons */
        .btn{background:var(--primary);color:#fff;border:none;padding:.5rem 1rem;border-radius:.5rem;cursor:pointer;font-size:.875rem;display:inline-flex;align-items:center;gap:.4rem;transition:all .2s}
        .btn:hover{background:var(--primary-hover);transform:translateY(-1px)}
        .btn-sm{padding:.375rem .75rem;font-size:.8rem}
        .btn-success{background:var(--success)}
        .btn-success:hover{background:#16a34a}
        .btn-danger{background:var(--error)}
        .btn-danger:hover{background:#dc2626}
        .btn-outline{background:transparent;border:1px solid var(--border);color:var(--text)}
        .btn-outline:hover{background:var(--input);border-color:var(--primary)}
        
        /* Main Layout */
        .main{display:grid;grid-template-columns:280px 1fr;min-height:calc(100vh - 60px)}
        
        /* Sidebar */
        .sidebar{background:var(--card);border-right:1px solid var(--border);padding:1.5rem;overflow-y:auto}
        .sidebar-section{margin-bottom:1.5rem}
        .sidebar-section h3{font-size:.75rem;text-transform:uppercase;color:var(--muted);margin-bottom:.75rem;letter-spacing:.05em}
        
        /* Stats */
        .stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
        .stat-card{background:var(--input);padding:.875rem;border-radius:.5rem;text-align:center}
        .stat-value{font-size:1.5rem;font-weight:700;color:var(--primary)}
        .stat-label{font-size:.7rem;color:var(--muted);margin-top:.25rem}
        
        /* Nav */
        .nav{display:flex;flex-direction:column;gap:.25rem}
        .nav-item{display:flex;align-items:center;gap:.5rem;padding:.625rem .875rem;border-radius:.5rem;cursor:pointer;transition:all .2s;color:var(--text);text-decoration:none}
        .nav-item:hover,.nav-item.active{background:var(--primary);color:#fff}
        .nav-item span{font-size:1.1rem}
        
        /* Content */
        .content{padding:1.5rem;overflow-y:auto}
        .content-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem}
        .content-header h2{font-size:1.25rem}
        
        /* Tabs */
        .tab-content{display:none}
        .tab-content.active{display:block}
        
        /* Upload Area */
        .upload-zone{border:2px dashed var(--border);border-radius:1rem;padding:3rem 2rem;text-align:center;cursor:pointer;transition:all .3s;background:var(--card)}
        .upload-zone:hover,.upload-zone.dragover{border-color:var(--primary);background:rgba(139,92,246,.1)}
        .upload-zone input{display:none}
        .upload-icon{font-size:3rem;margin-bottom:1rem}
        .upload-text{color:var(--muted);margin-bottom:.5rem}
        .upload-hint{font-size:.8rem;color:var(--muted);opacity:.7}
        
        /* Upload Progress */
        .upload-progress{margin-top:1.5rem}
        .upload-item{background:var(--input);padding:.875rem 1rem;border-radius:.5rem;margin-bottom:.5rem;display:flex;align-items:center;gap:1rem}
        .upload-item-name{flex:1;font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .upload-item-status{font-size:.8rem;padding:.25rem .5rem;border-radius:.25rem}
        .upload-item-status.pending{background:var(--input);color:var(--muted)}
        .upload-item-status.processing{background:rgba(59,130,246,.2);color:var(--info)}
        .upload-item-status.success{background:rgba(34,197,94,.2);color:var(--success)}
        .upload-item-status.error{background:rgba(239,68,68,.2);color:var(--error)}
        .progress-bar{height:4px;background:var(--border);border-radius:2px;overflow:hidden;margin-top:.5rem}
        .progress-bar-fill{height:100%;background:var(--primary);transition:width .3s}
        
        /* Form */
        .form-group{margin-bottom:1rem}
        .form-group label{display:block;margin-bottom:.5rem;font-size:.875rem;color:var(--muted)}
        input[type="text"],input[type="search"],textarea,select{width:100%;background:var(--input);border:1px solid var(--border);color:var(--text);padding:.625rem .875rem;border-radius:.5rem;font-size:.9rem;font-family:inherit}
        input:focus,textarea:focus,select:focus{outline:none;border-color:var(--primary)}
        textarea{min-height:150px;resize:vertical}
        
        /* Table */
        .table-wrapper{background:var(--card);border-radius:.75rem;overflow:hidden}
        table{width:100%;border-collapse:collapse}
        th,td{padding:.875rem 1rem;text-align:left}
        th{background:var(--input);font-size:.75rem;text-transform:uppercase;color:var(--muted);font-weight:600}
        tr{border-bottom:1px solid var(--border)}
        tr:last-child{border-bottom:none}
        tr:hover td{background:var(--card-hover)}
        .doc-title{font-weight:500}
        .doc-meta{font-size:.8rem;color:var(--muted)}
        
        /* Search Results */
        .search-results{margin-top:1.5rem}
        .search-item{background:var(--card);border:1px solid var(--border);border-radius:.75rem;padding:1rem 1.25rem;margin-bottom:.75rem}
        .search-item:hover{border-color:var(--primary)}
        .search-item-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem}
        .search-item-title{font-weight:600;color:var(--primary)}
        .search-item-sim{font-size:.8rem;padding:.25rem .5rem;border-radius:.25rem;background:rgba(139,92,246,.2);color:var(--primary)}
        .search-item-content{font-size:.9rem;color:var(--muted);line-height:1.6}
        
        /* Modal */
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:1000;padding:1rem}
        .modal-overlay.show{display:flex}
        .modal{background:var(--card);border-radius:1rem;width:100%;max-width:800px;max-height:90vh;display:flex;flex-direction:column}
        .modal-header{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
        .modal-header h3{font-size:1.1rem}
        .modal-close{background:none;border:none;color:var(--muted);font-size:1.5rem;cursor:pointer;padding:.25rem}
        .modal-close:hover{color:var(--text)}
        .modal-body{padding:1.5rem;overflow-y:auto;flex:1}
        .modal-footer{padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:.75rem}
        
        /* Chunk viewer */
        .chunk{background:var(--input);padding:1rem;border-radius:.5rem;margin-bottom:.75rem;transition:border-color .2s;border:1px solid transparent}
        .chunk:hover{border-color:var(--primary)}
        .chunk-header{font-size:.75rem;color:var(--muted);margin-bottom:.5rem}
        .chunk-content{font-size:.9rem;line-height:1.6;white-space:pre-wrap}
        
        /* Toast */
        .toast-container{position:fixed;bottom:1.5rem;right:1.5rem;z-index:1100;display:flex;flex-direction:column;gap:.5rem}
        .toast{background:var(--card);border:1px solid var(--border);padding:.875rem 1.25rem;border-radius:.5rem;display:flex;align-items:center;gap:.75rem;animation:slideIn .3s ease;min-width:280px;box-shadow:0 10px 25px -5px rgba(0,0,0,.3)}
        @keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
        .toast.success{border-color:var(--success)}
        .toast.error{border-color:var(--error)}
        .toast-icon{font-size:1.25rem}
        .toast-message{flex:1;font-size:.9rem}
        
        /* Pagination */
        .pagination{display:flex;justify-content:center;gap:.5rem;margin-top:1.5rem}
        .pagination button{background:var(--input);border:1px solid var(--border);color:var(--text);padding:.5rem .875rem;border-radius:.5rem;cursor:pointer}
        .pagination button:hover{border-color:var(--primary)}
        .pagination button.active{background:var(--primary);border-color:var(--primary)}
        .pagination button:disabled{opacity:.5;cursor:not-allowed}
        
        /* Empty state */
        .empty-state{text-align:center;padding:3rem;color:var(--muted)}
        .empty-state-icon{font-size:4rem;margin-bottom:1rem;opacity:.5}
        
        /* Responsive */
        @media(max-width:768px){
            .main{grid-template-columns:1fr}
            .sidebar{display:none}
            .header h1 span:last-child{display:none}
        }
    </style>
</head>
<body>

<header class="header">
    <h1>📚 <span>Vector Admin Panel</span></h1>
    <div class="header-actions">
        <button class="btn btn-outline btn-sm" onclick="testConnection()">🔌 Тест</button>
        <button class="btn btn-danger btn-sm" onclick="if(confirm('Видалити ВСІ документи?'))clearAll()">🗑️ Очистити</button>
        <a href="?logout=1" class="btn btn-outline btn-sm">🚪 Вийти</a>
    </div>
</header>

<div class="main">
    <aside class="sidebar">
        <div class="sidebar-section">
            <h3>📊 Статистика</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value" id="statFiles"><?= $stats['total_files'] ?? 0 ?></div>
                    <div class="stat-label">Документів</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="statChunks"><?= $stats['total_chunks'] ?? 0 ?></div>
                    <div class="stat-label">Чанків</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="statChars"><?= $stats['total_chars'] ? round($stats['total_chars'] / 1000) . 'K' : 0 ?></div>
                    <div class="stat-label">Символів</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="statSize"><?= $stats['db_size'] ?? 'N/A' ?></div>
                    <div class="stat-label">Розмір БД</div>
                </div>
            </div>
        </div>
        
        <div class="sidebar-section">
            <h3>📁 Меню</h3>
            <nav class="nav">
                <a class="nav-item active" data-tab="upload"><span>📤</span> Завантажити</a>
                <a class="nav-item" data-tab="add"><span>✏️</span> Додати текст</a>
                <a class="nav-item" data-tab="documents"><span>📑</span> Документи</a>
                <a class="nav-item" data-tab="search"><span>🔍</span> Пошук</a>
            </nav>
        </div>
        
        <div class="sidebar-section">
            <h3>ℹ️ Інформація</h3>
            <div style="font-size:.8rem;color:var(--muted);line-height:1.5">
                <p>Формати: .txt, .md, .html, .csv, .json</p>
                <p style="margin-top:.5rem">Макс. розмір: 10 MB</p>
                <p style="margin-top:.5rem">Модель: text-embedding-ada-002</p>
            </div>
        </div>
    </aside>
    
    <main class="content">
        <!-- Upload Tab -->
        <div id="tab-upload" class="tab-content active">
            <div class="content-header">
                <h2>📤 Завантаження файлів</h2>
            </div>
            
            <div class="upload-zone" id="uploadZone">
                <input type="file" id="fileInput" multiple accept=".txt,.md,.html,.csv,.json">
                <div class="upload-icon">📁</div>
                <div class="upload-text">Перетягніть файли сюди або клікніть для вибору</div>
                <div class="upload-hint">Підтримуються: .txt, .md, .html, .csv, .json (до 20 файлів)</div>
            </div>
            
            <div class="upload-progress" id="uploadProgress" style="display:none">
                <h3 style="font-size:.9rem;margin-bottom:1rem">📊 Прогрес завантаження</h3>
                <div id="uploadList"></div>
            </div>
        </div>
        
        <!-- Add Text Tab -->
        <div id="tab-add" class="tab-content">
            <div class="content-header">
                <h2>✏️ Додати текст вручну</h2>
            </div>
            
            <form id="addTextForm">
                <div class="form-group">
                    <label>Назва документа</label>
                    <input type="text" id="docTitle" placeholder="Наприклад: Опис компанії Rozetka">
                </div>
                <div class="form-group">
                    <label>Контент *</label>
                    <textarea id="docContent" placeholder="Вставте текст документа..." rows="10"></textarea>
                </div>
                <button type="submit" class="btn btn-success">➕ Додати в базу</button>
            </form>
        </div>
        
        <!-- Documents Tab -->
        <div id="tab-documents" class="tab-content">
            <div class="content-header">
                <h2>📑 Документи</h2>
                <button class="btn btn-sm" onclick="loadDocuments()">🔄 Оновити</button>
            </div>
            
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Назва</th>
                            <th>Чанків</th>
                            <th>Розмір</th>
                            <th>Дата</th>
                            <th>Дії</th>
                        </tr>
                    </thead>
                    <tbody id="documentsTable">
                        <tr><td colspan="5" class="empty-state">Завантаження...</td></tr>
                    </tbody>
                </table>
            </div>
            
            <div class="pagination" id="pagination"></div>
        </div>
        
        <!-- Search Tab -->
        <div id="tab-search" class="tab-content">
            <div class="content-header">
                <h2>🔍 Семантичний пошук</h2>
            </div>
            
            <form id="searchForm">
                <div class="form-group">
                    <div style="display:flex;gap:.75rem">
                        <input type="search" id="searchQuery" placeholder="Введіть пошуковий запит..." style="flex:1">
                        <button type="submit" class="btn">🔍 Шукати</button>
                    </div>
                </div>
            </form>
            
            <div class="search-results" id="searchResults">
                <div class="empty-state">
                    <div class="empty-state-icon">🔍</div>
                    <p>Введіть запит для семантичного пошуку по базі документів</p>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- View Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="viewModalTitle">Документ</h3>
            <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody"></div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('viewModal')">Закрити</button>
        </div>
    </div>
</div>

<!-- Edit Document Modal -->
<div class="modal-overlay" id="editDocModal">
    <div class="modal" style="max-width:500px">
        <div class="modal-header">
            <h3>✏️ Редагування документа</h3>
            <button class="modal-close" onclick="closeModal('editDocModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editDocFilename">
            <div class="form-group">
                <label>Назва документа</label>
                <input type="text" id="editDocTitle" placeholder="Введіть нову назву">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('editDocModal')">Скасувати</button>
            <button class="btn btn-success" onclick="saveDocumentTitle()">💾 Зберегти</button>
        </div>
    </div>
</div>

<!-- Edit Chunk Modal -->
<div class="modal-overlay" id="editChunkModal">
    <div class="modal" style="max-width:700px">
        <div class="modal-header">
            <h3>✏️ Редагування чанка <span id="editChunkIndex"></span></h3>
            <button class="modal-close" onclick="closeModal('editChunkModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editChunkId">
            <div class="form-group">
                <label>Контент чанка</label>
                <textarea id="editChunkContent" rows="12" placeholder="Введіть текст чанка..."></textarea>
            </div>
            <div style="font-size:.8rem;color:var(--muted);margin-top:.5rem">
                ⚠️ При збереженні буде автоматично оновлено векторний ембеддінг
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('editChunkModal')">Скасувати</button>
            <button class="btn btn-success" id="saveChunkBtn" onclick="saveChunk()">💾 Зберегти</button>
        </div>
    </div>
</div>

<!-- Add Chunk Modal -->
<div class="modal-overlay" id="addChunkModal">
    <div class="modal" style="max-width:700px">
        <div class="modal-header">
            <h3>➕ Додати новий чанк</h3>
            <button class="modal-close" onclick="closeModal('addChunkModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="addChunkFilename">
            <div class="form-group">
                <label>Контент нового чанка</label>
                <textarea id="addChunkContent" rows="12" placeholder="Введіть текст нового чанка..."></textarea>
            </div>
            <div style="font-size:.8rem;color:var(--muted);margin-top:.5rem">
                ℹ️ Новий чанк буде додано в кінець документа з автоматичним генеруванням ембеддінга
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('addChunkModal')">Скасувати</button>
            <button class="btn btn-success" id="addChunkBtn" onclick="saveNewChunk()">➕ Додати</button>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<script>
// ═══════════════════════════════════════════════════════════════
// TABS
// ═══════════════════════════════════════════════════════════════

document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', () => {
        const tab = item.dataset.tab;
        
        document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        
        item.classList.add('active');
        document.getElementById('tab-' + tab).classList.add('active');
        
        if (tab === 'documents') loadDocuments();
    });
});

// ═══════════════════════════════════════════════════════════════
// FILE UPLOAD
// ═══════════════════════════════════════════════════════════════

const uploadZone = document.getElementById('uploadZone');
const fileInput = document.getElementById('fileInput');
const uploadProgress = document.getElementById('uploadProgress');
const uploadList = document.getElementById('uploadList');

uploadZone.addEventListener('click', () => fileInput.click());

uploadZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadZone.classList.add('dragover');
});

uploadZone.addEventListener('dragleave', () => {
    uploadZone.classList.remove('dragover');
});

uploadZone.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadZone.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
});

fileInput.addEventListener('change', () => {
    handleFiles(fileInput.files);
});

async function handleFiles(files) {
    if (files.length === 0) return;
    if (files.length > 20) {
        toast('Максимум 20 файлів за раз', 'error');
        return;
    }
    
    uploadProgress.style.display = 'block';
    uploadList.innerHTML = '';
    
    // Створюємо список файлів
    const items = [];
    for (const file of files) {
        const id = 'file-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        items.push({ id, file, status: 'pending' });
        
        uploadList.innerHTML += `
            <div class="upload-item" id="${id}">
                <span class="upload-item-name">${file.name}</span>
                <span class="upload-item-status pending">Очікує</span>
            </div>
        `;
    }
    
    // Завантажуємо по черзі
    for (const item of items) {
        const el = document.getElementById(item.id);
        const statusEl = el.querySelector('.upload-item-status');
        
        statusEl.className = 'upload-item-status processing';
        statusEl.textContent = 'Обробка...';
        
        try {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'upload');
            formData.append('files[]', item.file);
            
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success && data.results[0]?.success) {
                const r = data.results[0];
                statusEl.className = 'upload-item-status success';
                statusEl.textContent = `✓ ${r.chunks_added} чанків`;
            } else {
                const error = data.results?.[0]?.error || data.error || 'Помилка';
                statusEl.className = 'upload-item-status error';
                statusEl.textContent = error;
            }
        } catch (e) {
            statusEl.className = 'upload-item-status error';
            statusEl.textContent = 'Помилка мережі';
        }
    }
    
    fileInput.value = '';
    loadStats();
    toast('Завантаження завершено', 'success');
}

// ═══════════════════════════════════════════════════════════════
// ADD TEXT
// ═══════════════════════════════════════════════════════════════

document.getElementById('addTextForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const title = document.getElementById('docTitle').value.trim();
    const content = document.getElementById('docContent').value.trim();
    
    if (!content) {
        toast('Введіть контент', 'error');
        return;
    }
    
    const btn = e.target.querySelector('button');
    btn.disabled = true;
    btn.textContent = '⏳ Обробка...';
    
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'add_text');
        formData.append('title', title);
        formData.append('content', content);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            toast(`Додано ${data.chunks_added} чанків`, 'success');
            document.getElementById('docTitle').value = '';
            document.getElementById('docContent').value = '';
            loadStats();
        } else {
            toast(data.error || 'Помилка', 'error');
        }
    } catch (e) {
        toast('Помилка мережі', 'error');
    }
    
    btn.disabled = false;
    btn.textContent = '➕ Додати в базу';
});

// ═══════════════════════════════════════════════════════════════
// DOCUMENTS
// ═══════════════════════════════════════════════════════════════

let currentPage = 1;

async function loadDocuments(page = 1) {
    currentPage = page;
    const tbody = document.getElementById('documentsTable');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:2rem">⏳ Завантаження...</td></tr>';
    
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'list');
        formData.append('page', page);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.documents && data.documents.length > 0) {
            tbody.innerHTML = data.documents.map(doc => {
                const chars = doc.total_chars > 1024 
                    ? Math.round(doc.total_chars / 1024) + ' KB'
                    : doc.total_chars + ' B';
                const date = new Date(doc.updated_at).toLocaleDateString('uk-UA');
                
                return `
                    <tr>
                        <td>
                            <div class="doc-title">${escapeHtml(doc.title || doc.filename)}</div>
                            <div class="doc-meta">${escapeHtml(doc.filename)}</div>
                        </td>
                        <td>${doc.chunks}</td>
                        <td>${chars}</td>
                        <td>${date}</td>
                        <td>
                            <button class="btn btn-sm btn-outline" onclick="viewDocument('${escapeHtml(doc.filename)}')" title="Переглянути">👁️</button>
                            <button class="btn btn-sm btn-outline" onclick="editDocument('${escapeHtml(doc.filename)}', '${escapeHtml(doc.title || doc.filename)}')" title="Редагувати">✏️</button>
                            <button class="btn btn-sm btn-danger" onclick="deleteDocument('${escapeHtml(doc.filename)}')" title="Видалити">🗑️</button>
                        </td>
                    </tr>
                `;
            }).join('');
            
            // Pagination
            const pagination = document.getElementById('pagination');
            if (data.total_pages > 1) {
                let html = '';
                html += `<button ${page === 1 ? 'disabled' : ''} onclick="loadDocuments(${page - 1})">◀</button>`;
                
                for (let i = 1; i <= data.total_pages; i++) {
                    if (i === 1 || i === data.total_pages || (i >= page - 2 && i <= page + 2)) {
                        html += `<button class="${i === page ? 'active' : ''}" onclick="loadDocuments(${i})">${i}</button>`;
                    } else if (i === page - 3 || i === page + 3) {
                        html += `<button disabled>...</button>`;
                    }
                }
                
                html += `<button ${page === data.total_pages ? 'disabled' : ''} onclick="loadDocuments(${page + 1})">▶</button>`;
                pagination.innerHTML = html;
            } else {
                pagination.innerHTML = '';
            }
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>База порожня. Завантажте документи.</p>
                    </td>
                </tr>
            `;
            document.getElementById('pagination').innerHTML = '';
        }
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--error)">❌ Помилка завантаження</td></tr>';
    }
}

let currentViewFilename = '';

async function viewDocument(filename) {
    currentViewFilename = filename;
    const modal = document.getElementById('viewModal');
    const title = document.getElementById('viewModalTitle');
    const body = document.getElementById('viewModalBody');
    
    title.innerHTML = `${escapeHtml(filename)} <button class="btn btn-sm btn-success" style="margin-left:1rem" onclick="openAddChunkModal('${escapeHtml(filename)}')">➕ Додати чанк</button>`;
    body.innerHTML = '<div style="text-align:center;padding:2rem">⏳ Завантаження...</div>';
    modal.classList.add('show');
    
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'view');
        formData.append('filename', filename);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.chunks && data.chunks.length > 0) {
            body.innerHTML = data.chunks.map((chunk, i) => `
                <div class="chunk" id="chunk-${chunk.id}">
                    <div class="chunk-header" style="display:flex;justify-content:space-between;align-items:center">
                        <span>Чанк ${i + 1} / ${data.chunks.length} <small style="color:var(--muted)">(ID: ${chunk.id})</small></span>
                        <div>
                            <button class="btn btn-sm btn-outline" onclick="editChunk(${chunk.id}, ${i + 1})" title="Редагувати">✏️</button>
                            <button class="btn btn-sm btn-danger" onclick="deleteChunk(${chunk.id}, '${escapeHtml(filename)}')" title="Видалити">🗑️</button>
                        </div>
                    </div>
                    <div class="chunk-content">${escapeHtml(chunk.content)}</div>
                </div>
            `).join('');
        } else {
            body.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--muted)">Документ порожній</div>';
        }
    } catch (e) {
        body.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--error)">❌ Помилка</div>';
    }
}

async function deleteDocument(filename) {
    if (!confirm(`Видалити "${filename}"?`)) return;
    
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'delete');
        formData.append('filename', filename);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            toast('Документ видалено', 'success');
            loadDocuments(currentPage);
            loadStats();
        } else {
            toast('Помилка видалення', 'error');
        }
    } catch (e) {
        toast('Помилка мережі', 'error');
    }
}

// ═══════════════════════════════════════════════════════════════
// SEARCH
// ═══════════════════════════════════════════════════════════════

document.getElementById('searchForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const query = document.getElementById('searchQuery').value.trim();
    if (!query) return;
    
    const results = document.getElementById('searchResults');
    results.innerHTML = '<div style="text-align:center;padding:2rem">⏳ Пошук...</div>';
    
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'search');
        formData.append('query', query);
        formData.append('limit', '10');
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.results && data.results.length > 0) {
            results.innerHTML = data.results.map((r, i) => {
                const sim = Math.round(r.similarity * 100);
                const simClass = sim >= 80 ? 'success' : sim >= 60 ? 'warning' : 'muted';
                const preview = r.content.length > 300 ? r.content.substring(0, 300) + '...' : r.content;
                
                return `
                    <div class="search-item">
                        <div class="search-item-header">
                            <span class="search-item-title">${escapeHtml(r.title || r.filename)}</span>
                            <span class="search-item-sim" style="background:var(--${simClass === 'muted' ? 'input' : simClass});color:var(--${simClass === 'muted' ? 'muted' : simClass})">${sim}%</span>
                        </div>
                        <div class="search-item-content">${escapeHtml(preview)}</div>
                    </div>
                `;
            }).join('');
        } else {
            results.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">🔍</div>
                    <p>Нічого не знайдено для "${escapeHtml(query)}"</p>
                </div>
            `;
        }
    } catch (e) {
        results.innerHTML = '<div style="text-align:center;color:var(--error);padding:2rem">❌ Помилка пошуку</div>';
    }
});

// ═══════════════════════════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════════════════════════

async function loadStats() {
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'stats');
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        document.getElementById('statFiles').textContent = data.total_files || 0;
        document.getElementById('statChunks').textContent = data.total_chunks || 0;
        document.getElementById('statChars').textContent = data.total_chars ? Math.round(data.total_chars / 1000) + 'K' : 0;
        document.getElementById('statSize').textContent = data.db_size || 'N/A';
    } catch (e) {}
}

async function testConnection() {
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'test');
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        toast(data.message, data.success ? 'success' : 'error');
    } catch (e) {
        toast('Помилка підключення', 'error');
    }
}

async function clearAll() {
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'clear');
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            toast(`Видалено ${data.deleted} чанків`, 'success');
            loadStats();
            loadDocuments();
        } else {
            toast('Помилка', 'error');
        }
    } catch (e) {
        toast('Помилка мережі', 'error');
    }
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function toast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    const icons = { success: '✅', error: '❌', info: 'ℹ️', warning: '⚠️' };
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <span class="toast-icon">${icons[type]}</span>
        <span class="toast-message">${escapeHtml(message)}</span>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideIn .3s ease reverse';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// ═══════════════════════════════════════════════════════════════
// РЕДАГУВАННЯ ДОКУМЕНТІВ І ЧАНКІВ
// ═══════════════════════════════════════════════════════════════

// Редагування назви документа
function editDocument(filename, currentTitle) {
    document.getElementById('editDocFilename').value = filename;
    document.getElementById('editDocTitle').value = currentTitle;
    document.getElementById('editDocModal').classList.add('show');
}

async function saveDocumentTitle() {
    const filename = document.getElementById('editDocFilename').value;
    const title = document.getElementById('editDocTitle').value.trim();
    
    if (!title) {
        toast('Введіть назву', 'error');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'update_document_title');
        formData.append('filename', filename);
        formData.append('title', title);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            toast('Назву оновлено', 'success');
            closeModal('editDocModal');
            loadDocuments(currentPage);
        } else {
            toast(data.error || 'Помилка', 'error');
        }
    } catch (e) {
        toast('Помилка мережі', 'error');
    }
}

// Редагування чанка
async function editChunk(id, chunkIndex) {
    document.getElementById('editChunkId').value = id;
    document.getElementById('editChunkIndex').textContent = `#${chunkIndex}`;
    document.getElementById('editChunkContent').value = '';
    document.getElementById('editChunkModal').classList.add('show');
    
    // Завантажуємо контент чанка
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'get_chunk');
        formData.append('id', id);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success && data.chunk) {
            document.getElementById('editChunkContent').value = data.chunk.content;
        } else {
            toast('Чанк не знайдено', 'error');
            closeModal('editChunkModal');
        }
    } catch (e) {
        toast('Помилка завантаження', 'error');
        closeModal('editChunkModal');
    }
}

async function saveChunk() {
    const id = document.getElementById('editChunkId').value;
    const content = document.getElementById('editChunkContent').value.trim();
    
    if (!content) {
        toast('Контент не може бути порожнім', 'error');
        return;
    }
    
    const btn = document.getElementById('saveChunkBtn');
    btn.disabled = true;
    btn.innerHTML = '⏳ Оновлення ембеддінга...';
    
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'update_chunk');
        formData.append('id', id);
        formData.append('content', content);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            toast('Чанк оновлено', 'success');
            closeModal('editChunkModal');
            // Оновлюємо вміст на сторінці
            if (currentViewFilename) {
                viewDocument(currentViewFilename);
            }
            loadStats();
        } else {
            toast(data.error || 'Помилка', 'error');
        }
    } catch (e) {
        toast('Помилка мережі', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '💾 Зберегти';
    }
}

// Видалення окремого чанка
async function deleteChunk(id, filename) {
    if (!confirm('Видалити цей чанк?')) return;
    
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'delete_chunk');
        formData.append('id', id);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            toast('Чанк видалено', 'success');
            // Видаляємо елемент зі сторінки
            const chunkEl = document.getElementById('chunk-' + id);
            if (chunkEl) {
                chunkEl.remove();
            }
            loadStats();
        } else {
            toast('Помилка видалення', 'error');
        }
    } catch (e) {
        toast('Помилка мережі', 'error');
    }
}

// Додавання нового чанка
function openAddChunkModal(filename) {
    document.getElementById('addChunkFilename').value = filename;
    document.getElementById('addChunkContent').value = '';
    document.getElementById('addChunkModal').classList.add('show');
}

async function saveNewChunk() {
    const filename = document.getElementById('addChunkFilename').value;
    const content = document.getElementById('addChunkContent').value.trim();
    
    if (!content) {
        toast('Введіть контент', 'error');
        return;
    }
    
    const btn = document.getElementById('addChunkBtn');
    btn.disabled = true;
    btn.innerHTML = '⏳ Генерація ембеддінга...';
    
    try {
        const formData = new FormData();
        formData.append('ajax', '1');
        formData.append('action', 'add_chunk');
        formData.append('filename', filename);
        formData.append('content', content);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await response.json();
        
        if (data.success) {
            toast('Чанк додано', 'success');
            closeModal('addChunkModal');
            // Оновлюємо перегляд документа
            viewDocument(filename);
            loadStats();
        } else {
            toast(data.error || 'Помилка', 'error');
        }
    } catch (e) {
        toast('Помилка мережі', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '➕ Додати';
    }
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) overlay.classList.remove('show');
    });
});

// Close modal on Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show'));
    }
});

// Init
loadStats();
</script>
</body>
</html>
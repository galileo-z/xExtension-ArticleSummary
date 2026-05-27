<?php

/**
 * Article Summary Controller
 * 文章总结控制器
 */
final class FreshExtension_ArticleSummary_Controller extends Minz_ActionController {
  /**
   * Handle the summarize action
   * 处理总结动作
   */
  public function summarizeAction(): void {
    // Start output buffering to prevent output before header() call
    // This is essential for JSON API responses because header() must be called before any output
    // $this->view->_layout(false) may trigger some output, causing "headers already sent" error
    // 开启输出缓冲区，防止在调用 header() 之前有输出
    // 这对于 JSON API 响应是必要的，因为 header() 必须在任何输出之前调用
    // $this->view->_layout(false) 可能会触发某些输出，导致 headers already sent 错误
    ob_start();
    @set_time_limit(180);
    
    $this->view->_layout(false);
    header('Content-Type: application/json');

    // Get configuration values from user settings
    // 从用户设置中获取配置值
    $oai_url = FreshRSS_Context::$user_conf->oai_url;
    $oai_key = FreshRSS_Context::$user_conf->oai_key;
    $oai_model = FreshRSS_Context::$user_conf->oai_model;
    $oai_prompt = FreshRSS_Context::$user_conf->oai_prompt;
    $oai_provider = FreshRSS_Context::$user_conf->oai_provider ?: 'openai';
    $oai_thinking = $this->toBool(FreshRSS_Context::$user_conf->oai_thinking ?? false);

    // Check if all required configurations are provided
    // 检查是否提供了所有必要的配置
    if (
      $this->isEmpty($oai_url)
      || ($this->isEmpty($oai_key) && !$this->allowsEmptyApiKey($oai_provider))
      || $this->isEmpty($oai_model)
      || $this->isEmpty($oai_prompt)
    ) {
      echo json_encode(array(
        'response' => array(
          'data' => 'missing config',
          'error' => 'configuration'
        ),
        'status' => 200
      ));
      return;
    }

    // Get article ID from request and fetch the article
    // 从请求中获取文章ID并获取文章
    $entry_id = Minz_Request::param('id');
    $entry_dao = FreshRSS_Factory::createEntryDao();
    $entry = $entry_dao->searchById($entry_id);

    if ($entry === null) {
      $this->jsonResponse(array(
        'response' => array(
          'data' => 'article not found',
          'error' => 'entry_not_found'
        ),
        'status' => 404
      ));
      return;
    }

    $title = $entry->title(); // Get article title
    $author = $entry->author(); // Get article author
    $content = $entry->content(); // Get article content

    $articleText = "Title: " . $title . "\nAuthor: " . $author . "\n\nContent: " . $this->htmlToMarkdown($content);

    try {
      $summary = $this->summarizeWithProvider(
        (string)$oai_provider,
        (string)$oai_url,
        (string)$oai_key,
        (string)$oai_model,
        (string)$oai_prompt,
        $oai_thinking,
        $articleText
      );

      $this->jsonResponse(array(
        'response' => array(
          'data' => $summary,
          'provider' => $oai_provider,
          'error' => null
        ),
        'status' => 200
      ));
    } catch (Throwable $error) {
      $this->jsonResponse(array(
        'response' => array(
          'data' => $error->getMessage(),
          'error' => 'ai_api'
        ),
        'status' => 200
      ));
    }
  }

  /**
   * Check if a value is empty
   * 检查值是否为空
   * 
   * @param mixed $item The value to check
   * @return bool True if the value is null or a blank string, false otherwise
   */
  private function isEmpty(mixed $item): bool {
    return $item === null || (is_string($item) && trim($item) === '');
  }

  private function toBool(mixed $value): bool {
    if (is_string($value)) {
      $value = strtolower(trim($value));
      return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    return $value === true || $value === 1;
  }

  /**
   * @param array<string, mixed> $body
   */
  private function addOpenAiCompatibleThinking(array &$body, string $provider, string $baseUrl, bool $thinkingEnabled): void {
    if ($provider === 'lmstudio') {
      $body['reasoning_effort'] = $thinkingEnabled ? 'medium' : 'none';
      if (!$thinkingEnabled) {
        $body['reasoning_tokens'] = 0;
      }
      return;
    }

    $host = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: ''));
    if ($host === 'api.openai.com') {
      return;
    }

    $body['enable_thinking'] = $thinkingEnabled;
  }

  /**
   * Check whether a provider can be used without an API key.
   *
   * @param string $provider AI provider identifier
   * @return bool True if the API key may be omitted
   */
  private function allowsEmptyApiKey(string $provider): bool {
    return in_array($provider, ['ollama', 'lmstudio'], true);
  }

  /**
   * Normalize OpenAI-compatible base URLs to the chat completions endpoint.
   *
   * Accepts either a base URL such as http://localhost:1234 or
   * http://localhost:1234/v1, or the full /chat/completions endpoint.
   */
  private function openAiChatCompletionsUrl(string $url): string {
    $url = rtrim(trim($url), '/');

    if (preg_match('#/chat/completions$#', $url)) {
      return $url;
    }

    if (!preg_match('#/v\d+(beta)?$#', $url)) {
      $url .= '/v1';
    }

    return $url . '/chat/completions';
  }

  /**
   * @param array<string, mixed> $data
   */
  private function jsonResponse(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  private function summarizeWithProvider(
    string $provider,
    string $baseUrl,
    string $apiKey,
    string $model,
    string $systemPrompt,
    bool $thinkingEnabled,
    string $articleText
  ): string {
    if ($provider === 'ollama') {
      return $this->summarizeOllama($baseUrl, $apiKey, $model, $systemPrompt, $thinkingEnabled, $articleText);
    }

    if ($provider === 'gemini') {
      return $this->summarizeGemini($baseUrl, $apiKey, $model, $systemPrompt, $thinkingEnabled, $articleText);
    }

    return $this->summarizeOpenAiCompatible($provider, $baseUrl, $apiKey, $model, $systemPrompt, $thinkingEnabled, $articleText);
  }

  private function summarizeOpenAiCompatible(
    string $provider,
    string $baseUrl,
    string $apiKey,
    string $model,
    string $systemPrompt,
    bool $thinkingEnabled,
    string $articleText
  ): string {
    $headers = ['Content-Type: application/json'];
    if (trim($apiKey) !== '') {
      $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $body = array(
      'model' => $model,
      'messages' => array(
        array(
          'role' => 'system',
          'content' => $systemPrompt,
        ),
        array(
          'role' => 'user',
          'content' => $articleText,
        ),
      ),
      'max_tokens' => 2048,
      'temperature' => 0.7,
      'n' => 1,
      'stream' => false,
    );

    $this->addOpenAiCompatibleThinking($body, $provider, $baseUrl, $thinkingEnabled);
    $json = $this->postJson($this->openAiChatCompletionsUrl($baseUrl), $body, $headers);

    $content = $json['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
      throw new RuntimeException('AI API response did not include summary text');
    }

    return $content;
  }

  private function summarizeOllama(
    string $baseUrl,
    string $apiKey,
    string $model,
    string $systemPrompt,
    bool $thinkingEnabled,
    string $articleText
  ): string {
    $headers = ['Content-Type: application/json'];
    if (trim($apiKey) !== '') {
      $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $json = $this->postJson(rtrim(trim($baseUrl), '/') . '/api/generate', array(
      'model' => $model,
      'system' => $systemPrompt,
      'prompt' => $articleText,
      'stream' => false,
      'think' => $thinkingEnabled,
    ), $headers);

    $content = $json['response'] ?? null;
    if (!is_string($content) || trim($content) === '') {
      throw new RuntimeException('Ollama response did not include summary text');
    }

    return $content;
  }

  private function summarizeGemini(
    string $baseUrl,
    string $apiKey,
    string $model,
    string $systemPrompt,
    bool $thinkingEnabled,
    string $articleText
  ): string {
    $baseUrl = rtrim(trim($baseUrl), '/');
    if (!preg_match('/\/v\d+(beta)?$/', $baseUrl)) {
      $baseUrl .= '/v1beta';
    }

    $url = $baseUrl . '/models/' . rawurlencode($model) . ':generateContent';
    if (trim($apiKey) !== '') {
      $url .= '?key=' . rawurlencode($apiKey);
    }

    $json = $this->postJson($url, array(
      'systemInstruction' => array(
        'parts' => array(array('text' => $systemPrompt)),
      ),
      'contents' => array(
        array(
          'parts' => array(array('text' => $articleText)),
        ),
      ),
      'generationConfig' => array(
        'thinkingConfig' => array(
          'thinkingBudget' => $thinkingEnabled ? -1 : 0,
        ),
      ),
    ), ['Content-Type: application/json']);

    $parts = $json['candidates'][0]['content']['parts'] ?? null;
    if (!is_array($parts)) {
      throw new RuntimeException('Gemini response did not include summary text');
    }

    $content = '';
    foreach ($parts as $part) {
      if (isset($part['text']) && is_string($part['text'])) {
        $content .= $part['text'];
      }
    }

    if (trim($content) === '') {
      throw new RuntimeException('Gemini response did not include summary text');
    }

    return $content;
  }

  /**
   * @param array<string, mixed> $body
   * @param string[] $headers
   * @return array<string, mixed>
   */
  private function postJson(string $url, array $body, array $headers): array {
    $requestBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($requestBody === false) {
      throw new RuntimeException('Failed to encode AI API request');
    }

    $responseBody = '';
    $statusCode = 0;

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      if ($ch === false) {
        throw new RuntimeException('Failed to initialize HTTP client');
      }

      curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
      ));

      $responseBody = curl_exec($ch);
      $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);
      curl_close($ch);

      if ($responseBody === false) {
        throw new RuntimeException('AI API request failed: ' . $curlError);
      }
    } else {
      $context = stream_context_create(array(
        'http' => array(
          'method' => 'POST',
          'header' => implode("\r\n", $headers),
          'content' => $requestBody,
          'ignore_errors' => true,
          'timeout' => 180,
        ),
      ));

      $responseBody = file_get_contents($url, false, $context);
      if ($responseBody === false) {
        throw new RuntimeException('AI API request failed');
      }

      $statusCode = $this->statusCodeFromHeaders($http_response_header ?? array());
    }

    if ($statusCode < 200 || $statusCode >= 300) {
      throw new RuntimeException('AI API returned HTTP ' . $statusCode . ': ' . $this->responseErrorMessage($responseBody));
    }

    $json = json_decode($responseBody, true);
    if (!is_array($json)) {
      throw new RuntimeException('AI API returned invalid JSON: ' . substr($responseBody, 0, 500));
    }

    return $json;
  }

  /**
   * @param string[] $headers
   */
  private function statusCodeFromHeaders(array $headers): int {
    foreach ($headers as $header) {
      if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
        return (int)$matches[1];
      }
    }

    return 0;
  }

  private function responseErrorMessage(string $responseBody): string {
    $json = json_decode($responseBody, true);
    if (is_array($json)) {
      $message = $json['error']['message'] ?? $json['message'] ?? $json['error'] ?? null;
      if (is_string($message) && $message !== '') {
        return $message;
      }
    }

    return substr($responseBody, 0, 500);
  }

  /**
   * Convert HTML content to Markdown format
   * 将HTML内容转换为Markdown格式
   * 
   * @param string $content HTML content to convert
   * @return string Markdown formatted content
   */
  private function htmlToMarkdown(string $content): string {
    // Create DOMDocument object
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Ignore HTML parsing errors
    $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
    libxml_clear_errors();

    // Create XPath object
    $xpath = new DOMXPath($dom);

    // Get all nodes
    $nodes = $xpath->query('//body/*');

    // Process all nodes
    $markdown = '';
    foreach ($nodes as $node) {
      $markdown .= $this->processNode($node, $xpath);
    }

    // Remove extra line breaks
    $markdown = preg_replace('/(\n){3,}/', "\n\n", $markdown);
    
    return $markdown;
  }

  /**
   * Process a single DOM node and convert it to Markdown
   * 处理单个DOM节点并将其转换为Markdown
   * 
   * @param DOMNode $node The DOM node to process
   * @param DOMXPath $xpath XPath object for querying nodes
   * @param int $indentLevel Indentation level for nested elements
   * @return string Markdown formatted content
   */
  private function processNode(DOMNode $node, DOMXPath $xpath, int $indentLevel = 0): string {
    $markdown = '';

    // Process text nodes
    if ($node->nodeType === XML_TEXT_NODE) {
      // Normalize whitespace to single space, don't completely remove
      $markdown .= preg_replace('/\s+/', ' ', $node->nodeValue);
    }

    // Process element nodes
    if ($node->nodeType === XML_ELEMENT_NODE) {
      switch ($node->nodeName) {
        case 'p':
        case 'div':
          foreach ($node->childNodes as $child) {
            $markdown .= $this->processNode($child, $xpath, $indentLevel);
          }
          $markdown .= "\n\n";
          break;
        case 'h1':
          $markdown .= "# ";
          $markdown .= $this->processNode($node->firstChild, $xpath);
          $markdown .= "\n\n";
          break;
        case 'h2':
          $markdown .= "## ";
          $markdown .= $this->processNode($node->firstChild, $xpath);
          $markdown .= "\n\n";
          break;
        case 'h3':
          $markdown .= "### ";
          $markdown .= $this->processNode($node->firstChild, $xpath);
          $markdown .= "\n\n";
          break;
        case 'h4':
          $markdown .= "#### ";
          $markdown .= $this->processNode($node->firstChild, $xpath);
          $markdown .= "\n\n";
          break;
        case 'h5':
          $markdown .= "##### ";
          $markdown .= $this->processNode($node->firstChild, $xpath);
          $markdown .= "\n\n";
          break;
        case 'h6':
          $markdown .= "###### ";
          $markdown .= $this->processNode($node->firstChild, $xpath);
          $markdown .= "\n\n";
          break;
        case 'a':
          // Convert links to code-style text instead of markdown links
          // 将链接转换为代码风格的文本而不是markdown链接
          $markdown .= "`";
          $markdown .= $this->processNode($node->firstChild, $xpath);
          $markdown .= "`";
          break;
        case 'img':
          $alt = $node->getAttribute('alt');
          $markdown .= "img: `" . $alt . "`";
          break;
        case 'strong':
        case 'b':
          $markdown .= "**";
          $markdown .= $this->processNode($node->firstChild, $xpath);
          $markdown .= "**";
          break;
        case 'em':
        case 'i':
          $markdown .= "*";
          $markdown .= $this->processNode($node->firstChild, $xpath);
          $markdown .= "*";
          break;
        case 'ul':
        case 'ol':
          $markdown .= "\n";
          foreach ($node->childNodes as $child) {
            if ($child->nodeName === 'li') {
              $markdown .= str_repeat("  ", $indentLevel) . "- ";
              $markdown .= $this->processNode($child, $xpath, $indentLevel + 1);
              $markdown .= "\n";
            }
          }
          $markdown .= "\n";
          break;
        case 'li':
          // Only process children - the parent ul/ol handles the bullet and newline
          foreach ($node->childNodes as $child) {
            $markdown .= $this->processNode($child, $xpath, $indentLevel + 1);
          }
          break;
        case 'br':
          $markdown .= "\n";
          break;
        case 'audio':
        case 'video':
          $alt = $node->getAttribute('alt');
          $markdown .= "[" . ($alt ? $alt : 'Media') . "]";
          break;
        default:
          // Tags not considered, only the text inside is kept
          // 不处理的标签，只保留内部文本
          foreach ($node->childNodes as $child) {
            $markdown .= $this->processNode($child, $xpath, $indentLevel);
          }
          break;
      }
    }

    return $markdown;
  }
}

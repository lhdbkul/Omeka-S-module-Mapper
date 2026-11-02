<?php declare(strict_types=1);

/**
 * Process transformation of xml via xsl.
 *
 * Supports both php native xsl extension (xslt 1.0) and external processors
 * like Saxon (xslt 2.0/3.0) via command line.
 *
 * @copyright Daniel Berthereau, 2015-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

namespace Mapper\Stdlib;

use DomDocument;
use Exception;
use Laminas\Log\Logger;
use Common\Stdlib\PsrMessage;
use XsltProcessor;

class ProcessXslt
{

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * External xslt processor command (generally saxon).
     *
     * Command with placeholders %1$s (input), %2$s (stylesheet), %3$s (output).
     *
     * @var string|null
     */
    protected $command;

    /**
     * @var string
     */
    protected $tempDir;

    /**
     * Original url for error messages (kept when file is downloaded).
     *
     * @var string|null
     */
    protected $originalUrl;

    /**
     * Max duration of the external command, in seconds (0 for no limit).
     *
     * @var int
     */
    protected $timeout = 600;

    /**
     * Max size of a downloaded file, in bytes (0 for no limit).
     *
     * @var int
     */
    protected $downloadMaxSize = 536870912;

    /**
     * Max idle duration of a download, in seconds (0 for the php default).
     *
     * @var int
     */
    protected $downloadTimeout = 60;

    public function __construct(
        Logger $logger,
        ?string $command = null,
        ?string $tempDir = null
    ) {
        $this->logger = $logger;
        $this->command = $command;
        $this->tempDir = $tempDir ?: sys_get_temp_dir();
    }

    /**
     * Apply an xslt stylesheet on a xml file and save result.
     *
     * @param string $url Path or url of input file.
     * @param string $stylesheet Path of the stylesheet.
     * @param string $output Path of the output file. If empty, use temp file.
     * @param array $parameters Parameters array.
     * @return string|null Path to the output file if ok, null else.
     * @throws Exception
     */
    public function __invoke(
        string $url,
        string $stylesheet,
        string $output = '',
        array $parameters = []
    ): ?string {
        return $this->process($url, $stylesheet, $output, $parameters);
    }

    /**
     * Apply an xslt stylesheet on a xml file and save result.
     *
     * @param string $url Path or url of input file.
     * @param string $stylesheet Path of the stylesheet.
     * @param string $output Path of the output file. If empty, use temp file.
     * @param array $parameters Parameters array.
     * @return string|null Path to the output file if ok, null else.
     * @throws Exception
     */
    public function process(
        string $url,
        string $stylesheet,
        string $output = '',
        array $parameters = []
    ): ?string {
        // Store original url for error messages.
        $this->originalUrl = $url;

        // Validate stylesheet first.
        if (!$this->isRemote($stylesheet)) {
            if (!is_file($stylesheet)) {
                $message = new PsrMessage(
                    'Stylesheet not found: {stylesheet}.', // @translate
                    ['stylesheet' => $stylesheet]
                );
                $this->logger->err($message->getMessage(), $message->getContext());
                throw new Exception((string) $message);
            }
            if (!is_readable($stylesheet)) {
                $message = new PsrMessage(
                    'Stylesheet not readable: {stylesheet}.', // @translate
                    ['stylesheet' => $stylesheet]
                );
                $this->logger->err($message->getMessage(), $message->getContext());
                throw new Exception((string) $message);
            }
        }

        // Input should be local to be processed by php or cli.
        $filepath = $url;
        $isRemote = $this->isRemote($url);
        if ($isRemote) {
            $filepath = $this->downloadToTemp($url);
            if ($filepath === null) {
                $message = new PsrMessage(
                    'The remote file {url} is not readable or empty.', // @translate
                    ['url' => $url]
                );
                $this->logger->err($message->getMessage(), $message->getContext());
                throw new Exception((string) $message);
            }
        } elseif (!is_file($filepath) || !is_readable($filepath) || !filesize($filepath)) {
            $message = new PsrMessage(
                'The input file {filepath} is not readable or empty.', // @translate
                ['filepath' => $filepath]
            );
            $this->logger->err($message->getMessage(), $message->getContext());
            throw new Exception((string) $message);
        }

        try {
            // Default is the internal xslt processor of php.
            $result = empty($this->command)
                ? $this->processXsltViaPhp($filepath, $stylesheet, $output, $parameters)
                : $this->processXsltViaExternal($filepath, $stylesheet, $output, $parameters);
        } finally {
            if ($isRemote && $filepath && file_exists($filepath)) {
                unlink($filepath);
            }
            $this->originalUrl = null;
        }

        return $result;
    }

    /**
     * Apply an xslt stylesheet on xml content and return result as string.
     *
     * @param string $xml Xml content to transform.
     * @param string $stylesheet Path of the stylesheet, or xsl content.
     * @param array $parameters Parameters array.
     * @return string|null Transformed content if ok, null on error.
     * @throws Exception
     */
    public function processString(
        string $xml,
        string $stylesheet,
        array $parameters = []
    ): ?string {
        if ($xml === '') {
            $message = 'The xml content is empty.'; // @translate
            $this->logger->err($message);
            throw new Exception($message);
        }

        $inputFile = null;
        $stylesheetFile = null;
        $stylesheetIsTemp = false;
        $outputFile = null;

        try {
            // Write xml content to temp file.
            $inputFile = $this->writeTempFile($xml, 'omk_src_');
            if ($inputFile === null) {
                $message = 'Unable to create a temporary input file.'; // @translate
                $this->logger->err($message);
                throw new Exception($message);
            }

            // Determine if stylesheet is a path or content.
            $stylesheetFile = $this->resolveStylesheet($stylesheet);
            if ($stylesheetFile === null) {
                // Stylesheet is content, write to temp file.
                $stylesheetFile = $this->writeTempFile($stylesheet, 'omk_xsl_');
                if ($stylesheetFile === null) {
                    $message = 'Unable to create a temporary stylesheet file.'; // @translate
                    $this->logger->err($message);
                    throw new Exception($message);
                }
                $stylesheetIsTemp = true;
            }

            // Process via file-based method.
            $outputFile = $this->process($inputFile, $stylesheetFile, '', $parameters);
            if ($outputFile === null || !is_file($outputFile)) {
                return null;
            }

            // Read and return result.
            $result = file_get_contents($outputFile);
            return $result !== false ? $result : null;
        } finally {
            // Cleanup temp files.
            if ($inputFile && is_file($inputFile)) {
                @unlink($inputFile);
            }
            if ($stylesheetIsTemp && $stylesheetFile && is_file($stylesheetFile)) {
                @unlink($stylesheetFile);
            }
            if ($outputFile && is_file($outputFile)) {
                @unlink($outputFile);
            }
        }
    }

    /**
     * Resolve stylesheet reference to a file path.
     *
     * @param string $stylesheet Path, url, or xsl content.
     * @return string|null File path if stylesheet is a file/url, null if content.
     */
    protected function resolveStylesheet(string $stylesheet): ?string
    {
        // Remote url.
        if ($this->isRemote($stylesheet)) {
            return $stylesheet;
        }
        // Absolute path.
        if (strpos($stylesheet, '/') === 0 && is_file($stylesheet)) {
            return $stylesheet;
        }
        // Relative path (check in current directory).
        if (is_file($stylesheet)) {
            return $stylesheet;
        }
        // Not a file, assume it's xsl content.
        return null;
    }

    /**
     * Write content to a temporary file.
     */
    protected function writeTempFile(string $content, string $prefix): ?string
    {
        $filepath = @tempnam($this->tempDir, $prefix);
        if ($filepath === false) {
            return null;
        }
        rename($filepath, $filepath . '.xml');
        $filepath .= '.xml';

        $result = file_put_contents($filepath, $content);
        if ($result === false) {
            @unlink($filepath);
            return null;
        }

        return $filepath;
    }

    /**
     * Download a remote file to a temporary location.
     */
    protected function downloadToTemp(string $url): ?string
    {
        $filepath = @tempnam($this->tempDir, 'omk_xml_');
        if ($filepath === false) {
            return null;
        }
        rename($filepath, $filepath . '.xml');
        $filepath .= '.xml';

        // Stream the file to avoid to load it in memory, with a limit.
        $context = stream_context_create($this->downloadTimeout
            ? ['http' => ['timeout' => $this->downloadTimeout], 'ftp' => ['timeout' => $this->downloadTimeout]]
            : []);
        $source = @fopen($url, 'rb', false, $context);
        $destination = $source ? @fopen($filepath, 'wb') : false;
        $size = $destination
            ? stream_copy_to_stream($source, $destination, $this->downloadMaxSize ? $this->downloadMaxSize + 1 : null)
            : false;
        if ($source) {
            fclose($source);
        }
        if ($destination) {
            fclose($destination);
        }
        $isTooLarge = $this->downloadMaxSize && $size > $this->downloadMaxSize;
        if (!$size || $isTooLarge) {
            if ($isTooLarge) {
                $this->logger->err(
                    'The file {url} is larger than {size} bytes.', // @translate
                    ['url' => $url, 'size' => $this->downloadMaxSize]
                );
            }
            @unlink($filepath);
            return null;
        }

        return $filepath;
    }

    /**
     * Apply xslt stylesheet on xml file via php and save output.
     *
     * @throws Exception
     */
    protected function processXsltViaPhp(
        string $filepath,
        string $stylesheet,
        string $output = '',
        array $parameters = []
    ): ?string {
        // Check xsl extension availability.
        if (!extension_loaded('xsl')) {
            $message = 'Php xsl extension is not loaded.'; // @translate
            $this->logger->err($message);
            throw new Exception($message);
        }

        if (empty($output)) {
            $output = $this->createTempFile();
            if ($output === null) {
                $message = 'Unable to create a temporary file.'; // @translate
                $this->logger->err($message);
                throw new Exception($message);
            }
        }

        libxml_use_internal_errors(true);

        $domXml = $this->domXmlLoad($filepath);
        $domXsl = $this->domXmlLoad($stylesheet);

        $proc = new XsltProcessor();

        // Security: disable php functions in xsl by default.
        if (method_exists($proc, 'setSecurityPrefs')) {
            $proc->setSecurityPrefs(XSL_SECPREF_DEFAULT);
        }

        $result = $proc->importStyleSheet($domXsl);
        if ($result === false) {
            $errors = $this->getLibxmlErrors() ?: 'Unknown error.';
            libxml_clear_errors();
            $message = new PsrMessage(
                'Xsl import error for {input} with {sheet}: {errors}', // @translate
                ['input' => $this->formatFileRef($filepath), 'sheet' => $this->formatFileRef($stylesheet), 'errors' => $errors]
            );
            $this->logger->err($message->getMessage(), $message->getContext());
            throw new Exception((string) $message);
        }

        $proc->setParameter('', $parameters);
        $result = $proc->transformToURI($domXml, $output);

        if ($result === false) {
            if (file_exists($output)) {
                @unlink($output);
            }
            $errors = $this->getLibxmlErrors() ?: 'Unknown error.';
            libxml_clear_errors();
            $message = new PsrMessage(
                'Xsl transformation error for {input} with {sheet}: {errors}', // @translate
                ['input' => $this->formatFileRef($filepath), 'sheet' => $this->formatFileRef($stylesheet), 'errors' => $errors]
            );
            $this->logger->err($message->getMessage(), $message->getContext());
            throw new Exception((string) $message);
        }

        if (!file_exists($output) || !filesize($output)) {
            if (file_exists($output)) {
                @unlink($output);
            }
            $errors = $this->getLibxmlErrors() ?: 'The output is empty.';
            libxml_clear_errors();
            $message = new PsrMessage(
                'Xsl transformation error for {input} with {sheet}: {errors}', // @translate
                ['input' => $this->formatFileRef($filepath), 'sheet' => $this->formatFileRef($stylesheet), 'errors' => $errors]
            );
            $this->logger->err($message->getMessage(), $message->getContext());
            throw new Exception((string) $message);
        }

        libxml_clear_errors();
        @chmod($output, 0640);

        return $output;
    }

    /**
     * Load xml or xslt file into a Dom document.
     *
     * @throws Exception
     */
    protected function domXmlLoad(string $filepath): DomDocument
    {
        libxml_use_internal_errors(true);

        $domDocument = new DomDocument();

        // No network access during parsing (external dtd and entities).
        if ($this->isRemote($filepath)) {
            $tempFile = $this->downloadToTemp($filepath);
            if ($tempFile === null) {
                $errors = $this->getLibxmlErrors();
                libxml_clear_errors();
                $message = new PsrMessage(
                    'Could not load {filepath}. Check access rights for this folder and sub-folders. {errors}', // @translate
                    ['filepath' => $filepath, 'errors' => $errors]
                );
                $this->logger->err($message->getMessage(), $message->getContext());
                throw new Exception((string) $message);
            }
            $loaded = $domDocument->load($tempFile, LIBXML_NONET);
            @unlink($tempFile);
        } else {
            $loaded = $domDocument->load($filepath, LIBXML_NONET);
        }

        if (!$loaded) {
            $errors = $this->getLibxmlErrors() ?: 'Unknown error.';
            libxml_clear_errors();
            $message = new PsrMessage(
                'Invalid xml in {file}: {errors}', // @translate
                ['file' => $this->formatFileRef($filepath), 'errors' => $errors]
            );
            $this->logger->err($message->getMessage(), $message->getContext());
            throw new Exception((string) $message);
        }

        return $domDocument;
    }

    /**
     * Apply xslt stylesheet on xml file via external processor and save output.
     *
     * @throws Exception
     */
    protected function processXsltViaExternal(
        string $filepath,
        string $stylesheet,
        string $output = '',
        array $parameters = []
    ): ?string {
        if (empty($output)) {
            $output = $this->createTempFile();
            if ($output === null) {
                $message = 'Unable to create a temporary file.'; // @translate
                $this->logger->err($message);
                throw new Exception($message);
            }
        }

        $isXsltproc = (bool) preg_match('~(^|/|\s)xsltproc(\s|$)~', $this->command);
        $paramsStr = '';
        if ($parameters) {
            $parts = [];
            if ($isXsltproc) {
                foreach ($parameters as $name => $parameter) {
                    $parts[] = '--stringparam ' . escapeshellarg((string) $name) . ' ' . escapeshellarg((string) $parameter);
                }
            } else {
                foreach ($parameters as $name => $parameter) {
                    $parts[] = escapeshellarg($name . '=' . $parameter);
                }
            }
            $paramsStr = implode(' ', $parts);
        }

        $hasParamsPlaceholder = strpos($this->command, '%4$s') !== false;
        if ($hasParamsPlaceholder) {
            $command = sprintf(
                $this->command,
                escapeshellarg($filepath),
                escapeshellarg($stylesheet),
                escapeshellarg($output),
                $paramsStr
            );
        } else {
            $command = sprintf(
                $this->command,
                escapeshellarg($filepath),
                escapeshellarg($stylesheet),
                escapeshellarg($output)
            );
            if ($paramsStr !== '') {
                if ($isXsltproc) {
                    $command = str_replace(
                        escapeshellarg($stylesheet),
                        $paramsStr . ' ' . escapeshellarg($stylesheet),
                        $command
                    );
                } else {
                    $command .= ' ' . $paramsStr;
                }
            }
        }
        // The result depends on the exit code: the processors may output
        // messages that are not errors, for example the warnings of java.
        $result = $this->runCommand($command);
        if ($result['exit_code'] !== 0) {
            if (file_exists($output)) {
                @unlink($output);
            }
            $errors = $result['timed_out']
                ? sprintf('The command was stopped after %d seconds.', $this->timeout)
                : (trim($result['errors']) ?: sprintf('The command failed with exit code %d.', $result['exit_code']));
            $message = new PsrMessage(
                'Xsl transformation error for {input} with {sheet}: {errors}', // @translate
                ['input' => $this->formatFileRef($filepath), 'sheet' => $this->formatFileRef($stylesheet), 'errors' => $errors]
            );
            $this->logger->err($message->getMessage(), $message->getContext());
            throw new Exception((string) $message);
        }
        if (trim($result['errors']) !== '') {
            $this->logger->info(
                'Xsl transformation messages for {input} with {sheet}: {messages}', // @translate
                ['input' => $this->formatFileRef($filepath), 'sheet' => $this->formatFileRef($stylesheet), 'messages' => trim($result['errors'])]
            );
        }

        if (!file_exists($output) || !filesize($output)) {
            if (file_exists($output)) {
                @unlink($output);
            }
            $message = new PsrMessage(
                'Xsl transformation error for {input} with {sheet}: {errors}', // @translate
                ['input' => $this->formatFileRef($filepath), 'sheet' => $this->formatFileRef($stylesheet), 'errors' => 'The output is empty.']
            );
            $this->logger->err($message->getMessage(), $message->getContext());
            throw new Exception((string) $message);
        }

        @chmod($output, 0640);

        return $output;
    }

    /**
     * Run a shell command with a timeout and get its exit code and errors.
     *
     * @return array{exit_code: int, errors: string, timed_out: bool}
     */
    protected function runCommand(string $command): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['exit_code' => -1, 'errors' => 'Unable to run the command.', 'timed_out' => false];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $errors = '';
        $timedOut = false;
        $start = microtime(true);
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 1)) {
                foreach ($read as $pipe) {
                    $chunk = (string) fread($pipe, 8192);
                    // The standard output is not used: the output is a file.
                    if ($pipe === $pipes[2]) {
                        $errors .= $chunk;
                    }
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if ($this->timeout && microtime(true) - $start > $this->timeout) {
                proc_terminate($process, 9);
                $timedOut = true;
                $exitCode = -1;
                break;
            }
        }

        $errors .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['exit_code' => $exitCode, 'errors' => $errors, 'timed_out' => $timedOut];
    }

    /**
     * Create a temporary file for output.
     */
    protected function createTempFile(): ?string
    {
        $output = @tempnam($this->tempDir, 'omk_xsl_');
        if ($output === false) {
            return null;
        }
        rename($output, $output . '.xml');
        return $output . '.xml';
    }

    /**
     * Determine if a uri is a remote url or a local path.
     */
    protected function isRemote(string $url): bool
    {
        return strpos($url, 'http://') === 0
            || strpos($url, 'https://') === 0
            || strpos($url, 'ftp://') === 0
            || strpos($url, 'sftp://') === 0;
    }

    /**
     * Format file reference for error messages.
     *
     * Returns original url if available, otherwise basename for local files.
     */
    protected function formatFileRef(string $filepath): string
    {
        // If this is the downloaded temp file, return original url.
        if ($this->originalUrl && !$this->isRemote($filepath)) {
            if (strpos($filepath, $this->tempDir) === 0) {
                return $this->originalUrl;
            }
        }
        // For remote urls, return full url.
        if ($this->isRemote($filepath)) {
            return $filepath;
        }
        // For local files, return basename.
        return basename($filepath);
    }

    /**
     * Get libxml errors as string.
     */
    protected function getLibxmlErrors(): string
    {
        $errors = libxml_get_errors();
        if (empty($errors)) {
            return '';
        }

        $messages = [];
        foreach ($errors as $error) {
            $messages[] = trim($error->message) . ' (line ' . $error->line . ')';
        }

        return implode('; ', $messages);
    }

    /**
     * Set the max duration of the external command, in seconds (0 for none).
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(0, $timeout);
        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Set the limits of the downloads: max size in bytes (0 for none) and max
     * idle duration in seconds (0 for the php default).
     */
    public function setDownloadLimits(int $maxSize, int $timeout): self
    {
        $this->downloadMaxSize = max(0, $maxSize);
        $this->downloadTimeout = max(0, $timeout);
        return $this;
    }

    public function getDownloadLimits(): array
    {
        return [
            'max_size' => $this->downloadMaxSize,
            'timeout' => $this->downloadTimeout,
        ];
    }

    /**
     * Set the external command for xslt processing.
     */
    public function setCommand(?string $command): self
    {
        $this->command = $command;
        return $this;
    }

    /**
     * Get the external command for xslt processing.
     */
    public function getCommand(): ?string
    {
        return $this->command;
    }

    /**
     * Set the temporary directory.
     */
    public function setTempDir(string $tempDir): self
    {
        $this->tempDir = $tempDir;
        return $this;
    }

    /**
     * Get the temporary directory.
     */
    public function getTempDir(): string
    {
        return $this->tempDir;
    }

    /**
     * Check if external processor is configured.
     */
    public function hasExternalProcessor(): bool
    {
        return !empty($this->command);
    }

    /**
     * Check if php xsl extension is available.
     */
    public function isPhpXslAvailable(): bool
    {
        return extension_loaded('xsl');
    }
}

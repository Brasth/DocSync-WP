<?php
/**
 * Private, owner-bound byte storage for import sessions.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use DocSyncWP\Security\EncryptionService;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores upload originals, canonical JSON, and private image assets.
 *
 * Bytes live outside the web root when `DOCSYNC_WP_PRIVATE_STORAGE_DIR` names
 * a writable absolute directory outside ABSPATH, the content directory, the
 * uploads directory, and the document root. Otherwise they are sealed in
 * 1 MiB chunks with `EncryptionService` inside
 * `uploads/docsync-wp-private/<random32hex>/`. When neither works, storage is
 * `unavailable` and every write fails closed. Nothing here creates posts,
 * attachments, or Media Library files.
 */
final class PrivateAssetStore {
	public const STORAGE_DIR_CONSTANT = 'DOCSYNC_WP_PRIVATE_STORAGE_DIR';
	public const ENCRYPTED_DIR_NAME   = 'docsync-wp-private';
	public const MODE_OUTSIDE_WEBROOT = 'outsideWebroot';
	public const MODE_ENCRYPTED       = 'encrypted';
	public const MODE_UNAVAILABLE     = 'unavailable';
	public const MAX_OBJECT_BYTES     = 52428800;

	private const CHUNK_BYTES          = 1048576;
	private const ORPHAN_GRACE_SECONDS = HOUR_IN_SECONDS;
	private const SESSION_PATTERN      = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/';
	private const FILE_ID_PATTERN      = '/^f_[a-f0-9]{16}$/';
	private const KEY_PATTERN          = '/^[a-z]_[a-f0-9]{16}(\.json)?$/';
	private const SITE_DIR_PATTERN     = '/^[a-f0-9]{32}$/';
	private const POINTER_SUFFIX       = '.ptr';
	private const CHUNK_MAGIC          = 'DSWP1';
	private const FILE_MODE            = 0600;
	private const DIR_MODE             = 0700;

	/**
	 * Encryption service.
	 *
	 * @var EncryptionService
	 */
	private EncryptionService $encryption;

	/**
	 * Direct filesystem, created on first use.
	 *
	 * @var \WP_Filesystem_Direct|null
	 */
	private ?\WP_Filesystem_Direct $filesystem = null;

	/**
	 * Resolved storage mode, cached per request.
	 *
	 * @var string|null
	 */
	private ?string $mode = null;

	/**
	 * Resolved base directory for the active mode.
	 *
	 * @var string
	 */
	private string $base_dir = '';

	/**
	 * Plaintext temp paths created by materialize(), keyed by path.
	 *
	 * @var array<string,true>
	 */
	private array $materialized = array();

	/**
	 * Constructor.
	 *
	 * @param EncryptionService $encryption Encryption service.
	 */
	public function __construct( EncryptionService $encryption ) {
		$this->encryption = $encryption;
	}

	/**
	 * Active storage mode: outsideWebroot, encrypted, or unavailable.
	 */
	public function storageMode(): string {
		if ( null === $this->mode ) {
			$this->resolveMode();
		}

		return (string) $this->mode;
	}

	/**
	 * Whether private storage can accept bytes.
	 */
	public function isAvailable(): bool {
		return self::MODE_UNAVAILABLE !== $this->storageMode();
	}

	/**
	 * Store an uploaded original for one session file.
	 *
	 * @param string $session_id Session ID.
	 * @param string $file_id    Session file ID (`f_` + 16 hex).
	 * @param string $tmp_path   Validated temporary upload path.
	 * @return array{key:string,byteSize:int,sha256:string}|WP_Error
	 */
	public function storeUpload( string $session_id, string $file_id, string $tmp_path ): array|WP_Error {
		if ( 1 !== preg_match( self::FILE_ID_PATTERN, $file_id ) ) {
			return $this->invalidKeyError();
		}

		$key        = 'o_' . substr( $file_id, 2 );
		$filesystem = $this->filesystem();

		if ( '' === $tmp_path || ! $filesystem->is_file( $tmp_path ) || ! $filesystem->is_readable( $tmp_path ) ) {
			return $this->unreadableError();
		}

		$size = absint( $filesystem->size( $tmp_path ) );

		if ( $size <= 0 || $size > self::MAX_OBJECT_BYTES ) {
			return $this->tooLargeError();
		}

		if ( self::MODE_OUTSIDE_WEBROOT === $this->storageMode() ) {
			$session_dir = $this->ensureSessionDir( $session_id );

			if ( is_wp_error( $session_dir ) ) {
				return $session_dir;
			}

			$staging = $session_dir . '/' . $key . '~' . $this->randomToken() . '.tmp';

			if ( ! $filesystem->copy( $tmp_path, $staging, true, self::FILE_MODE ) || ! $filesystem->move( $staging, $session_dir . '/' . $key, true ) ) {
				$filesystem->delete( $staging );

				return $this->writeError();
			}

			$hash = hash_file( 'sha256', $session_dir . '/' . $key );

			return array(
				'key'      => $key,
				'byteSize' => $size,
				'sha256'   => is_string( $hash ) ? $hash : '',
			);
		}

		$bytes = $filesystem->get_contents( $tmp_path );

		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $size ) {
			return $this->unreadableError();
		}

		return $this->put( $session_id, $key, $bytes );
	}

	/**
	 * Store bytes under a session key, replacing any previous value.
	 *
	 * @param string $session_id Session ID.
	 * @param string $key        Object key.
	 * @param string $bytes      Plaintext bytes.
	 * @return array{key:string,byteSize:int,sha256:string}|WP_Error
	 */
	public function put( string $session_id, string $key, string $bytes ): array|WP_Error {
		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			return $this->invalidKeyError();
		}

		$size = strlen( $bytes );

		if ( $size > self::MAX_OBJECT_BYTES ) {
			return $this->tooLargeError();
		}

		$session_dir = $this->ensureSessionDir( $session_id );

		if ( is_wp_error( $session_dir ) ) {
			return $session_dir;
		}

		$written = self::MODE_OUTSIDE_WEBROOT === $this->mode
			? $this->writePlain( $session_dir, $key, $bytes )
			: $this->writeEncrypted( $session_id, $session_dir, $key, $bytes );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'key'      => $key,
			'byteSize' => $size,
			'sha256'   => hash( 'sha256', $bytes ),
		);
	}

	/**
	 * Read the plaintext bytes of a session key.
	 *
	 * @param string $session_id Session ID.
	 * @param string $key        Object key.
	 * @return string|WP_Error
	 */
	public function read( string $session_id, string $key ): string|WP_Error {
		$session_dir = $this->sessionDir( $session_id, $key );

		if ( is_wp_error( $session_dir ) ) {
			return $session_dir;
		}

		if ( self::MODE_OUTSIDE_WEBROOT === $this->mode ) {
			$path = $session_dir . '/' . $key;

			if ( ! $this->filesystem()->is_file( $path ) ) {
				return $this->missingError();
			}

			$bytes = $this->filesystem()->get_contents( $path );

			return is_string( $bytes ) ? $bytes : $this->unreadableError();
		}

		return $this->readEncrypted( $session_id, $session_dir, $key );
	}

	/**
	 * Expose a session key as a plaintext file path for converters.
	 *
	 * Encrypted objects are decrypted into a private 0600 temp file. The caller
	 * must pass the path to release() when done; release() deletes only temp
	 * files created here, never the stored object.
	 *
	 * @param string $session_id Session ID.
	 * @param string $key        Object key.
	 * @return string|WP_Error Plaintext path.
	 */
	public function materialize( string $session_id, string $key ): string|WP_Error {
		$session_dir = $this->sessionDir( $session_id, $key );

		if ( is_wp_error( $session_dir ) ) {
			return $session_dir;
		}

		if ( self::MODE_OUTSIDE_WEBROOT === $this->mode ) {
			$path = $session_dir . '/' . $key;

			return $this->filesystem()->is_file( $path ) ? $path : $this->missingError();
		}

		$bytes = $this->readEncrypted( $session_id, $session_dir, $key );

		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$temp = wp_tempnam( 'docsync-wp-import' );

		if ( ! is_string( $temp ) || '' === $temp ) {
			return $this->writeError();
		}

		if ( ! $this->filesystem()->put_contents( $temp, $bytes, self::FILE_MODE ) ) {
			$this->filesystem()->delete( $temp );

			return $this->writeError();
		}

		$this->materialized[ $temp ] = true;

		return $temp;
	}

	/**
	 * Delete a plaintext temp file created by materialize().
	 *
	 * @param string $temp_path Path returned by materialize().
	 */
	public function release( string $temp_path ): void {
		if ( ! isset( $this->materialized[ $temp_path ] ) ) {
			return;
		}

		unset( $this->materialized[ $temp_path ] );
		$this->filesystem()->delete( $temp_path );
	}

	/**
	 * Delete every stored byte of one session.
	 *
	 * @param string $session_id Session ID.
	 */
	public function deleteSession( string $session_id ): bool {
		if ( 1 !== preg_match( self::SESSION_PATTERN, $session_id ) || ! $this->isAvailable() ) {
			return false;
		}

		$session_dir = $this->base_dir . '/' . $session_id;
		$filesystem  = $this->filesystem();

		if ( ! $filesystem->exists( $session_dir ) ) {
			return true;
		}

		$filesystem->delete( $session_dir, true );

		return ! $filesystem->exists( $session_dir );
	}

	/**
	 * Delete session directories that no live session record owns.
	 *
	 * Directories younger than one hour are kept so a session created during
	 * the scan is never purged. In encrypted mode, sibling site directories
	 * left behind by rotated salts are removed too, because their bytes can no
	 * longer be decrypted.
	 *
	 * @param array<int,string> $live_session_ids Session IDs with live records.
	 * @return int Number of removed directories.
	 */
	public function purgeOrphans( array $live_session_ids ): int {
		if ( ! $this->isAvailable() ) {
			return 0;
		}

		$live    = array_fill_keys( array_map( 'strval', $live_session_ids ), true );
		$cutoff  = time() - self::ORPHAN_GRACE_SECONDS;
		$removed = 0;

		foreach ( $this->listDirectories( $this->base_dir ) as $name => $modified ) {
			if ( 1 === preg_match( self::SESSION_PATTERN, $name ) && ! isset( $live[ $name ] ) && $modified < $cutoff ) {
				$this->filesystem()->delete( $this->base_dir . '/' . $name, true );
				++$removed;
			}
		}

		if ( self::MODE_ENCRYPTED === $this->mode ) {
			$root    = dirname( $this->base_dir );
			$current = basename( $this->base_dir );

			foreach ( $this->listDirectories( $root ) as $name => $modified ) {
				if ( $name !== $current && 1 === preg_match( self::SITE_DIR_PATTERN, $name ) && $modified < $cutoff ) {
					$this->filesystem()->delete( $root . '/' . $name, true );
					++$removed;
				}
			}
		}

		return $removed;
	}

	/**
	 * Resolve the storage mode and base directory, failing closed.
	 */
	private function resolveMode(): void {
		$this->mode     = self::MODE_UNAVAILABLE;
		$this->base_dir = '';

		$outside = $this->resolveOutsideWebrootDir();

		if ( '' !== $outside ) {
			$this->mode     = self::MODE_OUTSIDE_WEBROOT;
			$this->base_dir = $outside;

			return;
		}

		$encrypted = $this->resolveEncryptedDir();

		if ( '' !== $encrypted ) {
			$this->mode     = self::MODE_ENCRYPTED;
			$this->base_dir = $encrypted;
		}
	}

	/**
	 * Validate the configured outside-web-root directory.
	 *
	 * @return string Normalized directory, or '' when it is unusable.
	 */
	private function resolveOutsideWebrootDir(): string {
		if ( ! defined( self::STORAGE_DIR_CONSTANT ) ) {
			return '';
		}

		$configured = constant( self::STORAGE_DIR_CONSTANT );

		if ( ! is_string( $configured ) || '' === trim( $configured ) || ! path_is_absolute( $configured ) ) {
			return '';
		}

		$configured = untrailingslashit( wp_normalize_path( $configured ) );

		if ( $this->isPubliclyServed( $configured . '/' . self::ENCRYPTED_DIR_NAME ) ) {
			return '';
		}

		if ( ! $this->filesystem()->is_dir( $configured ) && ! wp_mkdir_p( $configured ) ) {
			return '';
		}

		$real = realpath( $configured );

		if ( false === $real ) {
			return '';
		}

		$base = untrailingslashit( wp_normalize_path( $real ) ) . '/' . self::ENCRYPTED_DIR_NAME;

		if ( $this->isPubliclyServed( $base ) || ! $this->ensureDir( $base ) ) {
			return '';
		}

		// Re-check the created directory so a symlink can never point storage into a served tree.
		$real_base = realpath( $base );

		if ( false === $real_base || $this->isPubliclyServed( untrailingslashit( wp_normalize_path( $real_base ) ) ) || ! $this->filesystem()->is_writable( $base ) ) {
			return '';
		}

		$this->writeProtectionFiles( $base );

		return $base;
	}

	/**
	 * Prepare the encrypted directory inside uploads.
	 *
	 * @return string Directory, or '' when encryption or uploads are unavailable.
	 */
	private function resolveEncryptedDir(): string {
		if ( ! $this->encryption->isAvailable() ) {
			return '';
		}

		$uploads = wp_upload_dir( null, false );

		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$root = untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ) . '/' . self::ENCRYPTED_DIR_NAME;
		$base = $root . '/' . substr( hash_hmac( 'sha256', 'docsync-wp-private-storage', wp_salt( 'auth' ) ), 0, 32 );

		if ( ! $this->ensureDir( $root ) || ! $this->ensureDir( $base ) ) {
			return '';
		}

		$this->writeProtectionFiles( $root );
		$this->writeProtectionFiles( $base );

		return $this->filesystem()->is_writable( $base ) ? $base : '';
	}

	/**
	 * Directories that may be served over HTTP.
	 *
	 * @return array<int,string>
	 */
	private function publicRoots(): array {
		$candidates = array( ABSPATH, WP_CONTENT_DIR );
		$uploads    = wp_upload_dir( null, false );

		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			$candidates[] = (string) $uploads['basedir'];
		}

		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '';

		if ( '' !== $document_root ) {
			$candidates[] = $document_root;
		}

		$roots = array();

		foreach ( $candidates as $candidate ) {
			$real = '' !== $candidate ? realpath( $candidate ) : false;

			if ( false !== $real ) {
				$roots[] = untrailingslashit( wp_normalize_path( $real ) );
			}
		}

		return array_values( array_unique( $roots ) );
	}

	/**
	 * Whether a path equals or sits inside a directory that may be served over HTTP.
	 *
	 * @param string $path Normalized absolute path.
	 */
	private function isPubliclyServed( string $path ): bool {
		foreach ( $this->publicRoots() as $public_root ) {
			if ( $this->isWithin( $path, $public_root ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a path equals or sits inside a root.
	 *
	 * @param string $path Normalized path.
	 * @param string $root Normalized root.
	 */
	private function isWithin( string $path, string $root ): bool {
		if ( '' === $root ) {
			return false;
		}

		return $path === $root || str_starts_with( $path . '/', $root . '/' );
	}

	/**
	 * Validate a session ID and key and return the session directory.
	 *
	 * @param string $session_id Session ID.
	 * @param string $key        Object key.
	 * @return string|WP_Error
	 */
	private function sessionDir( string $session_id, string $key ): string|WP_Error {
		if ( 1 !== preg_match( self::SESSION_PATTERN, $session_id ) || 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
			return $this->invalidKeyError();
		}

		if ( ! $this->isAvailable() ) {
			return $this->unavailableError();
		}

		return $this->base_dir . '/' . $session_id;
	}

	/**
	 * Create the session directory when needed.
	 *
	 * @param string $session_id Session ID.
	 * @return string|WP_Error
	 */
	private function ensureSessionDir( string $session_id ): string|WP_Error {
		if ( 1 !== preg_match( self::SESSION_PATTERN, $session_id ) ) {
			return $this->invalidKeyError();
		}

		if ( ! $this->isAvailable() ) {
			return $this->unavailableError();
		}

		$session_dir = $this->base_dir . '/' . $session_id;

		if ( ! $this->ensureDir( $session_dir ) ) {
			return $this->writeError();
		}

		$index = $session_dir . '/index.php';

		if ( ! $this->filesystem()->exists( $index ) ) {
			$this->filesystem()->put_contents( $index, "<?php\n// Silence is golden.\n", self::FILE_MODE );
		}

		return $session_dir;
	}

	/**
	 * Create one directory (parents must exist or be creatable).
	 *
	 * @param string $dir Directory.
	 */
	private function ensureDir( string $dir ): bool {
		$filesystem = $this->filesystem();

		if ( $filesystem->is_dir( $dir ) ) {
			return true;
		}

		if ( ! $filesystem->is_dir( dirname( $dir ) ) && ! wp_mkdir_p( dirname( $dir ) ) ) {
			return false;
		}

		return $filesystem->mkdir( $dir, self::DIR_MODE ) || $filesystem->is_dir( $dir );
	}

	/**
	 * Deny HTTP access to a storage directory on Apache, IIS, and directory listings.
	 *
	 * @param string $dir Directory.
	 */
	private function writeProtectionFiles( string $dir ): void {
		$files = array(
			'.htaccess'  => "# Brasth Document Sync private import storage.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);

		foreach ( $files as $name => $contents ) {
			$path = $dir . '/' . $name;

			if ( ! $this->filesystem()->exists( $path ) ) {
				$this->filesystem()->put_contents( $path, $contents, 0644 );
			}
		}
	}

	/**
	 * Write a plaintext object atomically (outside the web root only).
	 *
	 * @param string $session_dir Session directory.
	 * @param string $key         Object key.
	 * @param string $bytes       Bytes.
	 * @return true|WP_Error
	 */
	private function writePlain( string $session_dir, string $key, string $bytes ): bool|WP_Error {
		$staging = $session_dir . '/' . $key . '~' . $this->randomToken() . '.tmp';

		if ( ! $this->filesystem()->put_contents( $staging, $bytes, self::FILE_MODE ) || ! $this->filesystem()->move( $staging, $session_dir . '/' . $key, true ) ) {
			$this->filesystem()->delete( $staging );

			return $this->writeError();
		}

		return true;
	}

	/**
	 * Write an object as sealed 1 MiB chunks plus a pointer.
	 *
	 * Each chunk is bound to its session, key, generation token, and position,
	 * so chunks can never be swapped between objects or reordered. The pointer
	 * is replaced atomically after every chunk is written, and the previous
	 * generation's chunks are removed only afterwards.
	 *
	 * @param string $session_id  Session ID.
	 * @param string $session_dir Session directory.
	 * @param string $key         Object key.
	 * @param string $bytes       Plaintext bytes.
	 * @return true|WP_Error
	 */
	private function writeEncrypted( string $session_id, string $session_dir, string $key, string $bytes ): bool|WP_Error {
		$filesystem = $this->filesystem();
		$previous   = $this->readPointer( $session_dir, $key );
		$token      = $this->randomToken();
		$size       = strlen( $bytes );
		$chunks     = max( 1, (int) ceil( $size / self::CHUNK_BYTES ) );

		for ( $index = 0; $index < $chunks; ++$index ) {
			$sealed = $this->encryption->encrypt( $this->chunkHeader( $session_id, $key, $token, $index ) . substr( $bytes, $index * self::CHUNK_BYTES, self::CHUNK_BYTES ) );

			if ( is_wp_error( $sealed ) || '' === $sealed || ! $filesystem->put_contents( $this->chunkPath( $session_dir, $key, $token, $index ), $sealed, self::FILE_MODE ) ) {
				$this->deleteChunks( $session_dir, $key, $token, $index + 1 );

				return is_wp_error( $sealed ) ? $this->unavailableError() : $this->writeError();
			}
		}

		$pointer = wp_json_encode(
			array(
				'v'      => 1,
				'token'  => $token,
				'chunks' => $chunks,
				'size'   => $size,
				'sha256' => hash( 'sha256', $bytes ),
			)
		);
		$staging = $session_dir . '/' . $key . '~' . $token . '.ptrtmp';

		if ( ! is_string( $pointer ) || ! $filesystem->put_contents( $staging, $pointer, self::FILE_MODE ) || ! $filesystem->move( $staging, $session_dir . '/' . $key . self::POINTER_SUFFIX, true ) ) {
			$filesystem->delete( $staging );
			$this->deleteChunks( $session_dir, $key, $token, $chunks );

			return $this->writeError();
		}

		if ( null !== $previous && $previous['token'] !== $token ) {
			$this->deleteChunks( $session_dir, $key, $previous['token'], $previous['chunks'] );
		}

		return true;
	}

	/**
	 * Read and verify a sealed object.
	 *
	 * @param string $session_id  Session ID.
	 * @param string $session_dir Session directory.
	 * @param string $key         Object key.
	 * @return string|WP_Error
	 */
	private function readEncrypted( string $session_id, string $session_dir, string $key ): string|WP_Error {
		$pointer = $this->readPointer( $session_dir, $key );

		if ( null === $pointer ) {
			return $this->missingError();
		}

		$bytes = '';

		for ( $index = 0; $index < $pointer['chunks']; ++$index ) {
			$sealed = $this->filesystem()->get_contents( $this->chunkPath( $session_dir, $key, $pointer['token'], $index ) );

			if ( ! is_string( $sealed ) || '' === $sealed ) {
				return $this->unreadableError();
			}

			$plain  = $this->encryption->decrypt( $sealed );
			$header = $this->chunkHeader( $session_id, $key, $pointer['token'], $index );

			if ( is_wp_error( $plain ) || ! str_starts_with( $plain, $header ) ) {
				return $this->unreadableError();
			}

			$bytes .= substr( $plain, strlen( $header ) );
		}

		if ( strlen( $bytes ) !== $pointer['size'] || ! hash_equals( $pointer['sha256'], hash( 'sha256', $bytes ) ) ) {
			return $this->unreadableError();
		}

		return $bytes;
	}

	/**
	 * Read an object's chunk pointer.
	 *
	 * @param string $session_dir Session directory.
	 * @param string $key         Object key.
	 * @return array{token:string,chunks:int,size:int,sha256:string}|null
	 */
	private function readPointer( string $session_dir, string $key ): ?array {
		$path = $session_dir . '/' . $key . self::POINTER_SUFFIX;

		if ( ! $this->filesystem()->is_file( $path ) ) {
			return null;
		}

		$raw     = $this->filesystem()->get_contents( $path );
		$pointer = is_string( $raw ) ? json_decode( $raw, true ) : null;

		if (
			! is_array( $pointer )
			|| 1 !== ( $pointer['v'] ?? null )
			|| ! is_string( $pointer['token'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{16}$/', $pointer['token'] )
			|| ! is_int( $pointer['chunks'] ?? null )
			|| $pointer['chunks'] < 1
			|| ! is_int( $pointer['size'] ?? null )
			|| $pointer['size'] < 0
			|| $pointer['size'] > self::MAX_OBJECT_BYTES
			|| ! is_string( $pointer['sha256'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $pointer['sha256'] )
		) {
			return null;
		}

		return array(
			'token'  => $pointer['token'],
			'chunks' => $pointer['chunks'],
			'size'   => $pointer['size'],
			'sha256' => $pointer['sha256'],
		);
	}

	/**
	 * Delete the chunks of one object generation.
	 *
	 * @param string $session_dir Session directory.
	 * @param string $key         Object key.
	 * @param string $token       Generation token.
	 * @param int    $chunks      Chunk count.
	 */
	private function deleteChunks( string $session_dir, string $key, string $token, int $chunks ): void {
		for ( $index = 0; $index < $chunks; ++$index ) {
			$this->filesystem()->delete( $this->chunkPath( $session_dir, $key, $token, $index ) );
		}
	}

	/**
	 * Path of one sealed chunk.
	 *
	 * @param string $session_dir Session directory.
	 * @param string $key         Object key.
	 * @param string $token       Generation token.
	 * @param int    $index       Chunk index.
	 */
	private function chunkPath( string $session_dir, string $key, string $token, int $index ): string {
		return $session_dir . '/' . $key . '~' . $token . '.' . $index . '.enc';
	}

	/**
	 * Authenticated header sealed inside every chunk.
	 *
	 * @param string $session_id Session ID.
	 * @param string $key        Object key.
	 * @param string $token      Generation token.
	 * @param int    $index      Chunk index.
	 */
	private function chunkHeader( string $session_id, string $key, string $token, int $index ): string {
		return self::CHUNK_MAGIC . "\n" . $session_id . "\n" . $key . "\n" . $token . "\n" . $index . "\n";
	}

	/**
	 * Child directories of a path with their modification time.
	 *
	 * @param string $dir Directory.
	 * @return array<string,int>
	 */
	private function listDirectories( string $dir ): array {
		$listing = $this->filesystem()->is_dir( $dir ) ? $this->filesystem()->dirlist( $dir, false, false ) : false;
		$dirs    = array();

		if ( ! is_array( $listing ) ) {
			return $dirs;
		}

		foreach ( $listing as $name => $entry ) {
			if ( is_array( $entry ) && 'd' === ( $entry['type'] ?? '' ) ) {
				$dirs[ (string) $name ] = absint( $entry['lastmodunix'] ?? 0 );
			}
		}

		return $dirs;
	}

	/**
	 * Random 16-hex generation token.
	 */
	private function randomToken(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( Throwable $exception ) {
			return substr( hash( 'sha256', wp_generate_password( 32, true, true ) ), 0, 16 );
		}
	}

	/**
	 * Direct filesystem instance.
	 */
	private function filesystem(): \WP_Filesystem_Direct {
		if ( null === $this->filesystem ) {
			if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}

			$this->filesystem = new \WP_Filesystem_Direct( null );
		}

		return $this->filesystem;
	}

	/**
	 * Storage unavailable error.
	 */
	private function unavailableError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_storage_unavailable',
			__( 'Private upload storage is unavailable. Define DOCSYNC_WP_PRIVATE_STORAGE_DIR outside the web root, or enable OpenSSL so uploads can be encrypted.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Invalid session or key error.
	 */
	private function invalidKeyError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_asset_not_found',
			__( 'Brasth Document Sync could not find this private file.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Missing object error.
	 */
	private function missingError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_asset_not_found',
			__( 'Brasth Document Sync could not find this private file.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Unreadable or tampered object error.
	 */
	private function unreadableError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_storage_unreadable',
			__( 'Brasth Document Sync could not read this private file.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Write failure error.
	 */
	private function writeError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_storage_write_failed',
			__( 'Brasth Document Sync could not save this private file. Check that the storage directory is writable.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Oversized object error.
	 */
	private function tooLargeError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_file_too_large',
			__( 'This file is too large to store privately.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 413 )
		);
	}
}

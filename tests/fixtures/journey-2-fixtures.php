<?php
/**
 * Portable PDF and Office fixtures for the journey 2 regression suite.
 *
 * The generators below are the retained harness bytes (text, scanned, and
 * encrypted PDFs, plus the minimal DOCX/PPTX packages). Files are written only
 * under a caller-owned directory in the system temp dir, outside the web root
 * and outside the encrypted private-asset store. Nothing here touches
 * uploads/docsync-wp-private or changes directory modes inside WordPress.
 *
 * @package DocSyncWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'dswp_j2_web_roots' ) ) {
	/**
	 * Absolute web/content roots that must never hold journey-2 temp bytes.
	 *
	 * @return array<int,string>
	 */
	function dswp_j2_web_roots(): array {
		$roots = array( '/var/www/html' );

		if ( defined( 'ABSPATH' ) ) {
			$roots[] = rtrim( str_replace( '\\', '/', (string) ABSPATH ), '/' );
		}

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = rtrim( str_replace( '\\', '/', (string) WP_CONTENT_DIR ), '/' );
		}

		$docroot = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';

		if ( '' !== $docroot ) {
			$roots[] = rtrim( str_replace( '\\', '/', $docroot ), '/' );
		}

		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );

			if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				$roots[] = rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' );
			}
		}

		return array_values( array_unique( array_filter( $roots ) ) );
	}
}

if ( ! function_exists( 'dswp_j2_path_under_web_root' ) ) {
	/**
	 * Whether $dir equals or lives under a public WordPress tree.
	 *
	 * @param string $dir Absolute directory path (normalized, no trailing slash).
	 */
	function dswp_j2_path_under_web_root( string $dir ): bool {
		foreach ( dswp_j2_web_roots() as $root ) {
			if ( $dir === $root || str_starts_with( $dir, $root . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'dswp_j2_assert_safe_fixture_dir' ) ) {
	/**
	 * Refuse fixture paths inside the web root or the encrypted private store.
	 *
	 * A root-owned 0700 directory placed on the encrypted store (or the web
	 * root) previously made HTTP unreadable. Fixtures stay in the temp dir.
	 *
	 * @param string $dir Absolute directory path.
	 */
	function dswp_j2_assert_safe_fixture_dir( string $dir ): void {
		$dir = rtrim( str_replace( '\\', '/', $dir ), '/' );

		if ( '' === $dir || ! str_starts_with( $dir, '/' ) ) {
			throw new RuntimeException( 'Fixture directory must be absolute.' );
		}

		if ( str_contains( $dir, 'docsync-wp-private' ) ) {
			throw new RuntimeException( 'Fixture directory must not be the encrypted private store.' );
		}

		if ( dswp_j2_path_under_web_root( $dir ) ) {
			throw new RuntimeException( 'Fixture directory must be outside the web root: ' . $dir );
		}
	}
}

if ( ! function_exists( 'dswp_j2_assert_isolated_private_storage_dir' ) ) {
	/**
	 * Refuse non-isolated private-storage roots (site uploads or web trees).
	 *
	 * @param string $dir Absolute directory path.
	 */
	function dswp_j2_assert_isolated_private_storage_dir( string $dir ): void {
		$dir = rtrim( str_replace( '\\', '/', $dir ), '/' );

		if ( '' === $dir || ! str_starts_with( $dir, '/' ) ) {
			throw new RuntimeException( 'Private storage directory must be absolute.' );
		}

		if ( dswp_j2_path_under_web_root( $dir ) ) {
			throw new RuntimeException( 'Private storage must stay outside the web root and uploads: ' . $dir );
		}

		if ( ! str_starts_with( $dir, '/tmp/journey-2-private-' ) ) {
			throw new RuntimeException( 'Private storage must be a /tmp/journey-2-private-* root: ' . $dir );
		}
	}
}

if ( ! function_exists( 'dswp_j2_allocate_private_storage' ) ) {
	/**
	 * Own unique /tmp/journey-2-private-* root for PrivateAssetStore (mode 0700).
	 *
	 * Defines DOCSYNC_WP_PRIVATE_STORAGE_DIR when unset. An existing constant
	 * that is unsafe or non-isolated fails closed. Returns the owned root only
	 * when this call created it (caller must cleanup); otherwise null.
	 *
	 * @return string|null Newly created root, or null when reusing a safe existing constant.
	 */
	function dswp_j2_allocate_private_storage(): ?string {
		$constant = 'DOCSYNC_WP_PRIVATE_STORAGE_DIR';

		if ( defined( $constant ) ) {
			$existing = constant( $constant );

			if ( ! is_string( $existing ) || '' === trim( $existing ) ) {
				throw new RuntimeException( 'DOCSYNC_WP_PRIVATE_STORAGE_DIR is defined but empty.' );
			}

			dswp_j2_assert_isolated_private_storage_dir( $existing );

			return null;
		}

		$root = '/tmp/journey-2-private-' . bin2hex( random_bytes( 8 ) );
		dswp_j2_assert_isolated_private_storage_dir( $root );

		if ( ! mkdir( $root, 0700, true ) && ! is_dir( $root ) ) {
			throw new RuntimeException( 'Could not create private storage root: ' . $root );
		}

		chmod( $root, 0700 );
		define( $constant, $root );

		return $root;
	}
}

if ( ! function_exists( 'dswp_j2_cleanup_private_storage' ) ) {
	/**
	 * Recursively delete only a journey-2-owned /tmp/journey-2-private-* root.
	 *
	 * Never touches site uploads or uploads/docsync-wp-private.
	 *
	 * @param string|null $root Root returned by dswp_j2_allocate_private_storage().
	 */
	function dswp_j2_cleanup_private_storage( ?string $root ): void {
		if ( null === $root || '' === $root ) {
			return;
		}

		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		dswp_j2_assert_isolated_private_storage_dir( $root );

		if ( ! is_dir( $root ) || is_link( $root ) ) {
			return;
		}

		$real = realpath( $root );

		if ( false === $real ) {
			return;
		}

		$real = rtrim( str_replace( '\\', '/', $real ), '/' );
		dswp_j2_assert_isolated_private_storage_dir( $real );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$path = $item->getPathname();

			if ( $item->isLink() ) {
				continue;
			}

			if ( $item->isDir() ) {
				rmdir( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $real );
	}
}

if ( ! function_exists( 'dswp_j2_pdf_document' ) ) {
	/**
	 * Build a small PDF 1.4 document.
	 *
	 * Encrypted fixtures set a trailer /Encrypt reference. The stream is not
	 * cryptographically sealed; UploadValidator and PdfConverter reject the
	 * dictionary the same way the retained harness did.
	 *
	 * @param array<int,array{content:string,image?:bool}> $pages   Page content streams.
	 * @param bool                                          $encrypt Whether to attach an Encrypt dictionary.
	 */
	function dswp_j2_pdf_document( array $pages, bool $encrypt = false ): string {
		$objs    = array();
		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids    = array();
		$n       = 6;
		$objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$img     = str_repeat( "\xFF\x00\x00\x00\xFF\x00", 2 );
		$objs[5] = "<< /Type /XObject /Subtype /Image /Width 2 /Height 2 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length " . strlen( $img ) . " >>\nstream\n" . $img . "\nendstream";

		foreach ( $pages as $page ) {
			$pid       = $n++;
			$cid       = $n++;
			$resources = '/Font << /F1 3 0 R /F2 4 0 R >>' . ( ! empty( $page['image'] ) ? ' /XObject << /Im1 5 0 R >>' : '' );
			$objs[ $pid ] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << $resources >> /Contents $cid 0 R >>";
			$objs[ $cid ] = "<< /Length " . strlen( $page['content'] ) . " >>\nstream\n" . $page['content'] . "\nendstream";
			$kids[]       = "$pid 0 R";
		}

		$objs[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';
		$enc     = 0;

		if ( $encrypt ) {
			$objs[ $n ] = '<< /Filter /Standard /V 1 /R 2 /O (x) /U (y) /P -4 >>';
			$enc        = $n++;
		}

		ksort( $objs );
		$out     = "%PDF-1.4\n";
		$offsets = array();

		foreach ( $objs as $id => $body ) {
			$offsets[ $id ] = strlen( $out );
			$out           .= "$id 0 obj\n$body\nendobj\n";
		}

		$xref = strlen( $out );
		$max  = max( array_keys( $objs ) );
		$out .= "xref\n0 " . ( $max + 1 ) . "\n0000000000 65535 f \n";

		for ( $i = 1; $i <= $max; $i++ ) {
			$out .= sprintf( "%010d 00000 n \n", $offsets[ $i ] ?? 0 );
		}

		$out .= "trailer\n<< /Size " . ( $max + 1 ) . ' /Root 1 0 R' . ( $encrypt ? " /Encrypt $enc 0 R /ID [<00> <00>]" : '' ) . " >>\nstartxref\n$xref\n%%EOF\n";

		return $out;
	}
}

if ( ! function_exists( 'dswp_j2_pdf_text' ) ) {
	/**
	 * One PDF text-showing operation.
	 *
	 * @param int    $x    X in points.
	 * @param int    $y    Y in points.
	 * @param int    $size Font size.
	 * @param string $text Literal text.
	 * @param string $font F1 or F2.
	 */
	function dswp_j2_pdf_text( int $x, int $y, int $size, string $text, string $font = 'F1' ): string {
		return "BT /$font $size Tf $x $y Td (" . str_replace( array( '(', ')' ), array( '\\(', '\\)' ), $text ) . ") Tj ET\n";
	}
}

if ( ! function_exists( 'dswp_j2_report_page_bytes' ) ) {
	/**
	 * First page of the retained text fixture, including the figure.
	 */
	function dswp_j2_report_page_bytes(): string {
		$page1 = dswp_j2_pdf_text( 72, 720, 24, 'Quarterly Report', 'F2' );
		$y     = 690;

		foreach ( array( 'This report summarises the results of the quarter in detail.', 'Revenue grew strongly across every region we operate in today.', 'The team shipped many features and fixed numerous bugs too.' ) as $line ) {
			$page1 .= dswp_j2_pdf_text( 72, $y, 11, $line );
			$y     -= 14;
		}

		$y     -= 14;
		$page1 .= dswp_j2_pdf_text( 72, $y, 16, 'Highlights', 'F2' );
		$y     -= 22;

		foreach ( array( '- First highlight item here', '- Second highlight item here', '1. Numbered one', '2. Numbered two' ) as $line ) {
			$page1 .= dswp_j2_pdf_text( 80, $y, 11, $line );
			$y     -= 14;
		}

		$y -= 14;

		foreach ( array( array( 'Region', 'Q1', 'Q2' ), array( 'North', '100', '120' ), array( 'South', '90', '95' ), array( 'West', '70', '88' ) ) as $i => $row ) {
			foreach ( $row as $c => $cell ) {
				$page1 .= dswp_j2_pdf_text( 72 + $c * 150, $y, 11, $cell, 0 === $i ? 'F2' : 'F1' );
			}
			$y -= 14;
		}

		$page1 .= "q 200 0 0 100 72 200 cm /Im1 Do Q\n";

		return $page1;
	}
}

if ( ! function_exists( 'dswp_j2_text_pdf_bytes' ) ) {
	/**
	 * Two-page text PDF: title, list, table, one figure, then two columns.
	 */
	function dswp_j2_text_pdf_bytes(): string {
		$page2 = dswp_j2_pdf_text( 72, 740, 20, 'Two Column Page', 'F2' );
		$y      = 700;

		for ( $i = 1; $i <= 10; $i++ ) {
			$page2 .= dswp_j2_pdf_text( 50, $y, 11, "Left column line number $i text" );
			$page2 .= dswp_j2_pdf_text( 330, $y, 11, "Right column line number $i text" );
			$y     -= 14;
		}

		return dswp_j2_pdf_document(
			array(
				array(
					'content' => dswp_j2_report_page_bytes(),
					'image'   => true,
				),
				array(
					'content' => $page2,
				),
			)
		);
	}
}

if ( ! function_exists( 'dswp_j2_scanned_pdf_bytes' ) ) {
	/**
	 * One full-page image and no text operators. PdfConverter treats this as scanned.
	 */
	function dswp_j2_scanned_pdf_bytes(): string {
		return dswp_j2_pdf_document(
			array(
				array(
					'content' => "q 612 0 0 792 0 0 cm /Im1 Do Q\n",
					'image'   => true,
				),
			)
		);
	}
}

if ( ! function_exists( 'dswp_j2_encrypted_pdf_bytes' ) ) {
	/**
	 * Text PDF with a trailer Encrypt reference.
	 */
	function dswp_j2_encrypted_pdf_bytes(): string {
		return dswp_j2_pdf_document(
			array(
				array(
					'content' => dswp_j2_report_page_bytes(),
				),
			),
			true
		);
	}
}

if ( ! function_exists( 'dswp_j2_write_office_packages' ) ) {
	/**
	 * Minimal DOCX, macro DOCX, and PPTX packages from the retained Google harness.
	 *
	 * @param string $dir Fixture directory.
	 * @return array{docx:string,macro:string,pptx:string}
	 */
	function dswp_j2_write_office_packages( string $dir ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( 'ZipArchive is required to build DOCX and PPTX fixtures.' );
		}

		$docx = $dir . '/plan.docx';
		$zip  = new ZipArchive();

		if ( true !== $zip->open( $docx, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Could not create the DOCX fixture.' );
		}

		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>' );
		$zip->addFromString( 'word/document.xml', '<w:document/>' );
		$zip->close();

		$macro = $dir . '/macro.docx';

		if ( ! copy( $docx, $macro ) ) {
			throw new RuntimeException( 'Could not copy the macro DOCX fixture.' );
		}

		if ( true !== $zip->open( $macro ) ) {
			throw new RuntimeException( 'Could not open the macro DOCX fixture.' );
		}

		$zip->addFromString( 'word/vbaProject.bin', 'x' );
		$zip->close();

		$pptx = $dir . '/deck.pptx';

		if ( true !== $zip->open( $pptx, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Could not create the PPTX fixture.' );
		}

		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>' );
		$zip->addFromString( 'ppt/presentation.xml', '<p:presentation><p:sldIdLst><p:sldId id="256" r:id="rId2"/><p:sldId id="257" r:id="rId3"/><p:sldId id="258" r:id="rId4"/><p:sldId id="259" r:id="rId5"/></p:sldIdLst></p:presentation>' );
		$zip->addFromString( 'ppt/_rels/presentation.xml.rels', '<Relationships><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide2.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide3.xml"/><Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide4.xml"/></Relationships>' );
		$zip->addFromString( 'ppt/slides/slide1.xml', '<p:sld/>' );
		$zip->addFromString( 'ppt/slides/slide2.xml', '<p:sld><p:graphicFrame><p:xfrm><a:off x="' . ( 400 * 12700 ) . '" y="' . ( 120 * 12700 ) . '"/><a:ext cx="' . ( 200 * 12700 ) . '" cy="' . ( 150 * 12700 ) . '"/></p:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart r:id="rId9"/></a:graphicData></a:graphic></p:graphicFrame></p:sld>' );
		$zip->addFromString( 'ppt/slides/slide3.xml', '<p:sld><p:timing><p:tnLst><p:par><p:cTn id="1"/></p:par></p:tnLst></p:timing></p:sld>' );
		$zip->addFromString( 'ppt/slides/slide4.xml', '<p:sld/>' );
		$zip->close();

		return array(
			'docx'  => $docx,
			'macro' => $macro,
			'pptx'  => $pptx,
		);
	}
}

if ( ! function_exists( 'dswp_j2_make_fixtures' ) ) {
	/**
	 * Write PDFs and Office packages under the system temp directory.
	 *
	 * @return array{dir:string,text:string,scanned:string,encrypted:string,docx:string,macro:string,pptx:string}
	 */
	function dswp_j2_make_fixtures(): array {
		$base = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' );
		$dir  = $base . '/dswp-journey-2-' . bin2hex( random_bytes( 6 ) );
		dswp_j2_assert_safe_fixture_dir( $dir );

		if ( ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			throw new RuntimeException( 'Could not create the fixture directory.' );
		}

		$files = array(
			'text'      => $dir . '/text.pdf',
			'scanned'   => $dir . '/scanned.pdf',
			'encrypted' => $dir . '/encrypted.pdf',
		);
		$bytes = array(
			'text'      => dswp_j2_text_pdf_bytes(),
			'scanned'   => dswp_j2_scanned_pdf_bytes(),
			'encrypted' => dswp_j2_encrypted_pdf_bytes(),
		);

		foreach ( $bytes as $key => $content ) {
			if ( false === file_put_contents( $files[ $key ], $content ) ) {
				throw new RuntimeException( 'Could not write ' . $files[ $key ] );
			}
		}

		$office = dswp_j2_write_office_packages( $dir );

		return array(
			'dir'       => $dir,
			'text'      => $files['text'],
			'scanned'   => $files['scanned'],
			'encrypted' => $files['encrypted'],
			'docx'      => $office['docx'],
			'macro'     => $office['macro'],
			'pptx'      => $office['pptx'],
		);
	}
}

if ( ! function_exists( 'dswp_j2_cleanup_fixtures' ) ) {
	/**
	 * Delete only the files this generator created.
	 *
	 * @param array<string,string> $fixtures Fixture map from dswp_j2_make_fixtures().
	 */
	function dswp_j2_cleanup_fixtures( array $fixtures ): void {
		$dir = isset( $fixtures['dir'] ) ? (string) $fixtures['dir'] : '';

		if ( '' === $dir ) {
			return;
		}

		dswp_j2_assert_safe_fixture_dir( $dir );

		foreach ( array( 'text', 'scanned', 'encrypted', 'docx', 'macro', 'pptx' ) as $key ) {
			$path = isset( $fixtures[ $key ] ) ? (string) $fixtures[ $key ] : '';

			if ( '' === $path || ! str_starts_with( str_replace( '\\', '/', $path ), $dir . '/' ) ) {
				continue;
			}

			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}
}

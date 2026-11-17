<?php
/**
 * Excel (.xlsx) writer and reader without libraries (ZipArchive + SimpleXML).
 * Writer: several sheets, bold frozen header, inline strings, numbers kept as numbers.
 * Reader: every sheet, shared / inline / rich strings, booleans; refuses DOCTYPE / entities (XXE)
 * and oversized parts (zip bombs).
 *
 * Used by: files.php (downloads and uploads), tmg_io_xlsx_write().
 */

defined( 'ABSPATH' ) || exit;

class TMG_IO_Xlsx {

	/** Excel's own cell limit. */
	const CELL_MAX = 32767;

	/** Biggest XML part we agree to parse (uncompressed). */
	const PART_MAX = 209715200;

	public static function available(): bool {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * @param array<string, array<int, array>> $sheets sheet name => rows (row 0 = header).
	 * @return string Path to a temporary .xlsx file.
	 */
	public static function write( array $sheets, string $path = '' ): string {
		if ( ! self::available() ) {
			throw new RuntimeException( 'ZipArchive is not available on this server.' );
		}
		$path = $path ?: TMG_IO_Storage::new_path( 'xlsx', 'xlsx' );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Could not create the Excel file.' );
		}
		if ( ! $sheets ) {
			$sheets = array( 'Sheet1' => array() );
		}
		$names = array();
		$used  = array();
		$i     = 0;
		foreach ( $sheets as $name => $rows ) {
			++$i;
			$base = trim( mb_substr( preg_replace( '/[\\\\\/\?\*\[\]:]/', ' ', (string) $name ), 0, 31 ) );
			$base = '' === $base ? 'Sheet' . $i : $base;
			$n    = $base;
			$k    = 2;
			while ( isset( $used[ mb_strtolower( $n ) ] ) ) {
				$n = mb_substr( $base, 0, 28 ) . ' ' . $k++;
			}
			$used[ mb_strtolower( $n ) ] = true;
			$names[ $i ]                 = $n;
			$zip->addFromString( "xl/worksheets/sheet{$i}.xml", self::sheet_xml( (array) $rows ) );
		}

		$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		$wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
		$wr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ( $names as $n => $name ) {
			$ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$wb .= '<sheet name="' . self::esc( $name ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$wr .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
		}
		$wr .= '<Relationship Id="rId' . ( count( $names ) + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
		$ct .= '</Types>';
		$wb .= '</sheets></workbook>';

		$zip->addFromString( '[Content_Types].xml', $ct );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', $wb );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $wr );
		$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>' );
		$zip->close();
		return $path;
	}

	private static function sheet_xml( array $rows ): string {
		$x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
		foreach ( array_values( $rows ) as $r => $row ) {
			$x .= '<row r="' . ( $r + 1 ) . '">';
			foreach ( array_values( (array) $row ) as $c => $v ) {
				$ref   = self::col( $c ) . ( $r + 1 );
				$style = 0 === $r ? ' s="1"' : '';
				if ( null === $v || '' === $v ) {
					continue;
				}
				if ( is_bool( $v ) ) {
					$v = $v ? 1 : 0;
				}
				if ( is_int( $v ) || ( is_float( $v ) && is_finite( $v ) ) || ( is_string( $v ) && preg_match( '/^-?(0|[1-9]\d{0,14})(\.\d{1,10})?$/', $v ) ) ) {
					$x .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
				} else {
					$v = is_array( $v ) || is_object( $v ) ? (string) wp_json_encode( $v ) : (string) $v;
					if ( mb_strlen( $v ) > self::CELL_MAX ) {
						$v = mb_substr( $v, 0, self::CELL_MAX );
					}
					$x .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::esc( $v ) . '</t></is></c>';
				}
			}
			$x .= '</row>';
		}
		return $x . '</sheetData></worksheet>';
	}

	public static function col( int $i ): string {
		$s = '';
		for ( $i++; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
			$s = chr( 65 + ( $i - 1 ) % 26 ) . $s;
		}
		return $s;
	}

	private static function esc( string $s ): string {
		$s = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s );
		return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Parse one XML part safely: no DTDs (so no external or expanding entities), no network.
	 */
	public static function parse_xml( string $xml ): ?SimpleXMLElement {
		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $xml ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return false === $doc ? null : $doc;
	}

	private static function part( ZipArchive $zip, string $name ): ?string {
		$stat = $zip->statName( $name );
		if ( ! $stat || $stat['size'] > self::PART_MAX ) {
			return null;
		}
		$data = $zip->getFromName( $name );
		return false === $data ? null : $data;
	}

	/**
	 * Read every worksheet.
	 *
	 * @return array<string, array<int, array>> sheet name => rows. Throws on unsafe or broken files.
	 */
	public static function read( string $path ): array {
		if ( ! self::available() ) {
			throw new RuntimeException( 'ZipArchive is not available on this server.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new RuntimeException( 'This is not a valid Excel (.xlsx) file.' );
		}
		try {
			$wb_raw   = self::part( $zip, 'xl/workbook.xml' );
			$rels_raw = self::part( $zip, 'xl/_rels/workbook.xml.rels' );
			if ( null === $wb_raw ) {
				throw new RuntimeException( 'This is not a valid Excel (.xlsx) file.' );
			}
			$wb = self::parse_xml( $wb_raw );
			if ( ! $wb ) {
				throw new RuntimeException( 'The Excel file contains unsafe or invalid XML.' );
			}
			$shared = array();
			$ss_raw = self::part( $zip, 'xl/sharedStrings.xml' );
			if ( null !== $ss_raw ) {
				$ss = self::parse_xml( $ss_raw );
				if ( ! $ss ) {
					throw new RuntimeException( 'The Excel file contains unsafe or invalid XML.' );
				}
				foreach ( $ss->si as $si ) {
					$shared[] = self::rich_text( $si );
				}
			}
			$targets = array();
			if ( null !== $rels_raw ) {
				$rels = self::parse_xml( $rels_raw );
				if ( $rels ) {
					foreach ( $rels->Relationship as $rel ) {
						$t                                  = ltrim( (string) $rel['Target'], '/' );
						$targets[ (string) $rel['Id'] ] = 0 === strpos( $t, 'xl/' ) ? $t : 'xl/' . $t;
					}
				}
			}
			$out = array();
			$n   = 0;
			foreach ( $wb->sheets->sheet as $sheet ) {
				++$n;
				$rid  = (string) $sheet->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
				$file = $targets[ $rid ] ?? "xl/worksheets/sheet{$n}.xml";
				if ( false !== strpos( $file, '..' ) ) {
					continue;
				}
				$raw = self::part( $zip, $file );
				if ( null === $raw ) {
					continue;
				}
				$xml = self::parse_xml( $raw );
				if ( ! $xml ) {
					throw new RuntimeException( 'The Excel file contains unsafe or invalid XML.' );
				}
				$name         = (string) $sheet['name'];
				$out[ $name ] = self::sheet_rows( $xml, $shared );
			}
			return $out;
		} finally {
			$zip->close();
		}
	}

	private static function rich_text( SimpleXMLElement $node ): string {
		if ( isset( $node->t ) ) {
			return (string) $node->t;
		}
		$t = '';
		foreach ( $node->r as $run ) {
			$t .= (string) $run->t;
		}
		return $t;
	}

	private static function sheet_rows( SimpleXMLElement $xml, array $shared ): array {
		$rows = array();
		if ( ! isset( $xml->sheetData ) ) {
			return $rows;
		}
		foreach ( $xml->sheetData->row as $row ) {
			$cells = array();
			$next  = 0;
			foreach ( $row->c as $c ) {
				$idx = preg_match( '/^([A-Z]+)/', (string) $c['r'], $m ) ? self::col_index( $m[1] ) : $next;
				$t   = (string) $c['t'];
				if ( 's' === $t ) {
					$v = $shared[ (int) $c->v ] ?? '';
				} elseif ( 'inlineStr' === $t ) {
					$v = self::rich_text( $c->is );
				} elseif ( 'b' === $t ) {
					$v = '1' === (string) $c->v ? '1' : '0';
				} else {
					$v = (string) $c->v;
				}
				$cells[ $idx ] = $v;
				$next          = $idx + 1;
			}
			$r = (int) $row['r'];
			$r = $r > 0 ? $r - 1 : count( $rows );
			if ( $cells ) {
				$max   = max( array_keys( $cells ) );
				$clean = array();
				for ( $i = 0; $i <= $max; $i++ ) {
					$clean[] = $cells[ $i ] ?? '';
				}
				$rows[ $r ] = $clean;
			}
		}
		if ( ! $rows ) {
			return array();
		}
		// Fill skipped empty rows so row numbers stay meaningful, then reindex.
		$max = max( array_keys( $rows ) );
		$out = array();
		for ( $i = 0; $i <= $max; $i++ ) {
			$out[] = $rows[ $i ] ?? array();
		}
		return $out;
	}

	public static function col_index( string $letters ): int {
		$n = 0;
		foreach ( str_split( $letters ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n - 1;
	}
}

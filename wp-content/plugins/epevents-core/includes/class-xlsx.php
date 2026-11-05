<?php
/**
 * Minimal dependency-free XLSX writer / reader (ZipArchive + XML).
 */

defined( 'ABSPATH' ) || exit;

class EPC_Xlsx {

	/**
	 * Build an .xlsx file.
	 *
	 * @param array<string, array<int, array>> $sheets sheet name => rows (first row = header).
	 * @return string Path to a temp file.
	 */
	public static function write( array $sheets ): string {
		$path = wp_tempnam( 'epc-export.xlsx' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		$names = array();
		$i     = 0;
		foreach ( $sheets as $name => $rows ) {
			++$i;
			$names[ $i ] = mb_substr( preg_replace( '/[\\\\\/\?\*\[\]:]/', ' ', (string) $name ), 0, 31 ) ?: 'Sheet' . $i;
			$zip->addFromString( "xl/worksheets/sheet{$i}.xml", self::sheet_xml( $rows ) );
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
			foreach ( array_values( $row ) as $c => $v ) {
				$ref   = self::col( $c ) . ( $r + 1 );
				$style = 0 === $r ? ' s="1"' : '';
				if ( is_int( $v ) || is_float( $v ) || ( is_string( $v ) && preg_match( '/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $v ) ) ) {
					$x .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
				} else {
					$v  = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
					$x .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::esc( $v ) . '</t></is></c>';
				}
			}
			$x .= '</row>';
		}
		return $x . '</sheetData></worksheet>';
	}

	private static function col( int $i ): string {
		$s = '';
		for ( $i++; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
			$s = chr( 65 + ( $i - 1 ) % 26 ) . $s;
		}
		return $s;
	}

	private static function esc( string $s ): string {
		$s = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s );
		return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Read the first worksheet of an .xlsx file into rows (array of arrays).
	 */
	public static function read( string $path ): array {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array();
		}
		$shared = array();
		$ss     = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( $ss ) {
			$xml = simplexml_load_string( $ss, 'SimpleXMLElement', LIBXML_NONET );
			foreach ( $xml->si as $si ) {
				if ( isset( $si->t ) ) {
					$shared[] = (string) $si->t;
				} else {
					$t = '';
					foreach ( $si->r as $run ) {
						$t .= (string) $run->t;
					}
					$shared[] = $t;
				}
			}
		}
		// Resolve first sheet path from workbook relationships.
		$sheet_path = 'xl/worksheets/sheet1.xml';
		$rels       = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		$wb         = $zip->getFromName( 'xl/workbook.xml' );
		if ( $rels && $wb ) {
			$wbx  = simplexml_load_string( $wb, 'SimpleXMLElement', LIBXML_NONET );
			$rid  = (string) $wbx->sheets->sheet[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
			$relx = simplexml_load_string( $rels, 'SimpleXMLElement', LIBXML_NONET );
			foreach ( $relx->Relationship as $rel ) {
				if ( (string) $rel['Id'] === $rid ) {
					$target     = ltrim( (string) $rel['Target'], '/' );
					$sheet_path = str_starts_with( $target, 'xl/' ) ? $target : 'xl/' . $target;
				}
			}
		}
		$data = $zip->getFromName( $sheet_path );
		$zip->close();
		if ( ! $data ) {
			return array();
		}
		$xml  = simplexml_load_string( $data, 'SimpleXMLElement', LIBXML_NONET );
		$rows = array();
		foreach ( $xml->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $c ) {
				preg_match( '/^([A-Z]+)/', (string) $c['r'], $m );
				$idx  = self::col_index( $m[1] ?? 'A' );
				$type = (string) $c['t'];
				if ( 's' === $type ) {
					$v = $shared[ (int) $c->v ] ?? '';
				} elseif ( 'inlineStr' === $type ) {
					$v = (string) $c->is->t;
				} else {
					$v = (string) $c->v;
				}
				$cells[ $idx ] = $v;
			}
			if ( $cells ) {
				$max = max( array_keys( $cells ) );
				$out = array();
				for ( $i = 0; $i <= $max; $i++ ) {
					$out[] = $cells[ $i ] ?? '';
				}
				$rows[] = $out;
			}
		}
		return $rows;
	}

	private static function col_index( string $letters ): int {
		$n = 0;
		foreach ( str_split( $letters ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n - 1;
	}
}

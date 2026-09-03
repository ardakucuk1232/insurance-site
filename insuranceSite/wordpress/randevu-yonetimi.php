<?php
/**
 * Plugin Name: Örnek Sigorta — Randevu Sistemi
 * Description: Sitedeki randevu takvimini besler. Müsait saatleri hesaplar, randevuyu veritabanına yazar, çakışmayı veritabanı düzeyinde engeller ve yönetim panelinde günlük randevu listesi sunar. Excel'e aktarılabilir.
 * Version: 1.0.0
 * Author: Arda
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Acente_Randevu {

	const TABLO    = 'acente_randevu';
	const NONCE    = 'acente_rnd_nonce';
	const OPT      = 'acente_randevu_ayar';
	const KAPASITE = 4;
	const PENCERE  = 900;

	public function __construct() {
		register_activation_hook( __FILE__, [ $this, 'kurulum' ] );

		add_action( 'wp_ajax_acente_rnd_saatler',        [ $this, 'ajax_saatler' ] );
		add_action( 'wp_ajax_nopriv_acente_rnd_saatler', [ $this, 'ajax_saatler' ] );
		add_action( 'wp_ajax_acente_rnd_kaydet',         [ $this, 'ajax_kaydet' ] );
		add_action( 'wp_ajax_nopriv_acente_rnd_kaydet',  [ $this, 'ajax_kaydet' ] );

		add_action( 'admin_menu',                 [ $this, 'menu' ] );
		add_action( 'admin_post_acente_rnd_durum', [ $this, 'durum_degistir' ] );
		add_action( 'admin_post_acente_rnd_csv',   [ $this, 'csv_indir' ] );
		add_action( 'admin_init',                 [ $this, 'ayar_kaydet' ] );
		add_action( 'wp_enqueue_scripts',         [ $this, 'degiskenleri_yayinla' ] );
	}

	public function kurulum() {
		global $wpdb;
		$tablo   = $wpdb->prefix . self::TABLO;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE {$tablo} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			randevu_gun DATE NOT NULL,
			randevu_saat TIME NOT NULL,
			ad_soyad VARCHAR(120) NOT NULL,
			telefon VARCHAR(20) NOT NULL,
			konu VARCHAR(120) NOT NULL DEFAULT '',
			gorusme_sekli VARCHAR(40) NOT NULL DEFAULT '',
			durum ENUM('bekliyor','onaylandi','iptal') NOT NULL DEFAULT 'bekliyor',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			olusturma DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY slot (randevu_gun, randevu_saat),
			KEY gun (randevu_gun),
			KEY durum (durum)
		) {$charset};" );

		if ( ! get_option( self::OPT ) ) {
			add_option( self::OPT, $this->varsayilan_ayar() );
		}
	}

	private function varsayilan_ayar() {
		return [
			'baslangic'    => '09:00',
			'bitis'        => '17:30',
			'adim'         => 30,
			'kapali_gunler'=> [ 0 ],
			'ileri_gun'    => 90,
			'en_erken_dk'  => 60,
			'bildirim'     => 1,
		];
	}

	private function ayar() {
		return wp_parse_args( (array) get_option( self::OPT, [] ), $this->varsayilan_ayar() );
	}

	public function degiskenleri_yayinla() {
		wp_localize_script( 'acente-tema', 'acenteRandevu', [
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
			'ayar'  => $this->ayar(),
		] );
	}

	private function saat_listesi() {
		$a = $this->ayar();
		$out = [];
		$b = $this->dk( $a['baslangic'] );
		$s = $this->dk( $a['bitis'] );
		for ( $m = $b; $m <= $s; $m += (int) $a['adim'] ) {
			$out[] = sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
		}
		return $out;
	}

	private function dk( $hhmm ) {
		$p = explode( ':', $hhmm );
		return ( (int) $p[0] ) * 60 + ( (int) ( $p[1] ?? 0 ) );
	}

	private function gun_acik_mi( $gun ) {
		$a  = $this->ayar();
		$ts = strtotime( $gun );
		if ( ! $ts ) return false;
		if ( in_array( (int) date( 'w', $ts ), (array) $a['kapali_gunler'], true ) ) return false;
		$bugun = strtotime( current_time( 'Y-m-d' ) );
		if ( $ts < $bugun ) return false;
		if ( $ts > strtotime( '+' . (int) $a['ileri_gun'] . ' days', $bugun ) ) return false;
		return true;
	}

	private function ip_al() {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private function telefon_normalle( $ham ) {
		$t = preg_replace( '/\D/', '', (string) $ham );
		if ( strpos( $t, '0090' ) === 0 )      $t = substr( $t, 4 );
		elseif ( strpos( $t, '90' ) === 0 && strlen( $t ) === 12 ) $t = substr( $t, 2 );
		if ( strpos( $t, '0' ) === 0 )         $t = substr( $t, 1 );
		return $t;
	}

	private function ad_temizle( $ham ) {
		$ad = preg_replace( '/[^\p{L}\s\'.\x{2019}\-]/u', '', (string) $ham );
		$ad = preg_replace( '/\s{2,}/u', ' ', trim( $ad ) );
		return mb_substr( $ad, 0, 60 );
	}

	private function ad_gecerli( $ad ) {
		return mb_strlen( preg_replace( '/[^\p{L}]/u', '', $ad ) ) >= 3;
	}

	public function ajax_saatler() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$gun = sanitize_text_field( wp_unslash( $_REQUEST['gun'] ?? '' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $gun ) || ! $this->gun_acik_mi( $gun ) ) {
			wp_send_json_success( [ 'gun' => $gun, 'saatler' => [] ] );
		}

		global $wpdb;
		$dolu = $wpdb->get_col( $wpdb->prepare(
			"SELECT TIME_FORMAT(randevu_saat,'%%H:%%i') FROM {$wpdb->prefix}" . self::TABLO .
			" WHERE randevu_gun = %s AND durum <> 'iptal'", $gun
		) );

		$a       = $this->ayar();
		$bugunMu = ( $gun === current_time( 'Y-m-d' ) );
		$simdiDk = (int) current_time( 'H' ) * 60 + (int) current_time( 'i' );

		$out = [];
		foreach ( $this->saat_listesi() as $saat ) {
			$gecmis = $bugunMu && ( $this->dk( $saat ) <= $simdiDk + (int) $a['en_erken_dk'] );
			$out[]  = [
				'saat'   => $saat,
				'musait' => ( ! in_array( $saat, $dolu, true ) && ! $gecmis ),
			];
		}
		wp_send_json_success( [ 'gun' => $gun, 'saatler' => $out ] );
	}

	public function ajax_kaydet() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! empty( $_POST['web_sitesi'] ) ) {
			wp_send_json_success( [ 'mesaj' => 'Randevunuz oluşturuldu.' ] );
		}

		$ip      = $this->ip_al();
		$anahtar = 'acente_rnd_' . md5( $ip );
		$sayac   = (int) get_transient( $anahtar );
		if ( $sayac >= self::KAPASITE ) {
			wp_send_json_error( [ 'mesaj' => 'Çok fazla deneme yapıldı. Lütfen bizi telefonla arayın.' ], 429 );
		}
		set_transient( $anahtar, $sayac + 1, self::PENCERE );

		$gun  = sanitize_text_field( wp_unslash( $_POST['gun'] ?? '' ) );
		$saat = sanitize_text_field( wp_unslash( $_POST['saat'] ?? '' ) );
		$ad   = $this->ad_temizle( wp_unslash( $_POST['ad_soyad'] ?? '' ) );
		$tel  = $this->telefon_normalle( $_POST['telefon'] ?? '' );
		$konu = sanitize_text_field( wp_unslash( $_POST['konu'] ?? '' ) );
		$yer  = sanitize_text_field( wp_unslash( $_POST['gorusme_sekli'] ?? '' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $gun ) || ! $this->gun_acik_mi( $gun ) ) {
			wp_send_json_error( [ 'mesaj' => 'Seçilen gün randevuya kapalı.' ], 400 );
		}
		if ( ! in_array( $saat, $this->saat_listesi(), true ) ) {
			wp_send_json_error( [ 'mesaj' => 'Geçersiz saat seçimi.' ], 400 );
		}
		if ( ! $this->ad_gecerli( $ad ) ) {
			wp_send_json_error( [ 'mesaj' => 'Lütfen adınızı ve soyadınızı harflerle yazın.' ], 400 );
		}
		if ( strlen( $tel ) !== 10 || $tel[0] !== '5' ) {
			wp_send_json_error( [ 'mesaj' => 'Geçerli bir cep telefonu numarası girin.' ], 400 );
		}

		$a       = $this->ayar();
		$bugunMu = ( $gun === current_time( 'Y-m-d' ) );
		$simdiDk = (int) current_time( 'H' ) * 60 + (int) current_time( 'i' );
		if ( $bugunMu && $this->dk( $saat ) <= $simdiDk + (int) $a['en_erken_dk'] ) {
			wp_send_json_error( [ 'mesaj' => 'Bu saat için randevu süresi doldu. Lütfen başka bir saat seçin.' ], 409 );
		}

		global $wpdb;
		$sonuc = $wpdb->insert( $wpdb->prefix . self::TABLO, [
			'randevu_gun'   => $gun,
			'randevu_saat'  => $saat . ':00',
			'ad_soyad'      => $ad,
			'telefon'       => '0' . $tel,
			'konu'          => $konu,
			'gorusme_sekli' => $yer,
			'durum'         => 'bekliyor',
			'ip'            => $ip,
			'olusturma'     => current_time( 'mysql' ),
		], [ '%s','%s','%s','%s','%s','%s','%s','%s','%s' ] );

		if ( false === $sonuc ) {

			wp_send_json_error( [
				'mesaj' => 'Seçtiğiniz saat bu arada doldu. Lütfen başka bir saat seçin.',
				'kod'   => 'dolu',
			], 409 );
		}

		if ( ! empty( $a['bildirim'] ) ) {
			wp_mail(
				get_option( 'admin_email' ),
				'Yeni randevu — ' . $ad . ' · ' . $gun . ' ' . $saat,
				"Yeni bir randevu oluşturuldu.\n\n" .
				"Tarih: {$gun} {$saat}\nAd Soyad: {$ad}\nTelefon: 0{$tel}\n" .
				"Konu: {$konu}\nGörüşme şekli: {$yer}\n\n" .
				'Panel: ' . admin_url( 'admin.php?page=acente-randevu' )
			);
		}

		wp_send_json_success( [
			'mesaj' => 'Randevunuz oluşturuldu.',
			'gun'   => $gun,
			'saat'  => $saat,
		] );
	}

	public function menu() {
		global $wpdb;
		$bekleyen = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLO .
			" WHERE durum='bekliyor' AND randevu_gun >= CURDATE()"
		);
		$rozet = $bekleyen ? " <span class='awaiting-mod'>{$bekleyen}</span>" : '';
		add_menu_page( 'Randevular', 'Randevular' . $rozet, 'manage_options',
			'acente-randevu', [ $this, 'ekran' ], 'dashicons-calendar-alt', 27 );
		add_submenu_page( 'acente-randevu', 'Randevu Ayarları', 'Ayarlar', 'manage_options',
			'acente-randevu-ayar', [ $this, 'ayar_ekrani' ] );
	}

	public function ekran() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		global $wpdb;
		$tablo = $wpdb->prefix . self::TABLO;

		$filtre = in_array( $_GET['durum'] ?? '', [ 'bekliyor','onaylandi','iptal' ], true ) ? $_GET['durum'] : '';
		$kapsam = ( $_GET['kapsam'] ?? 'gelecek' ) === 'tumu' ? 'tumu' : 'gelecek';

		$where = [];
		if ( $filtre ) $where[] = $wpdb->prepare( 'durum = %s', $filtre );
		if ( 'gelecek' === $kapsam ) $where[] = 'randevu_gun >= CURDATE()';
		$sql = "SELECT * FROM {$tablo}" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' )
		     . ' ORDER BY randevu_gun ASC, randevu_saat ASC LIMIT 500';
		$kayitlar = $wpdb->get_results( $sql );

		$csv = wp_nonce_url( admin_url( 'admin-post.php?action=acente_rnd_csv' ), 'acente_rnd_csv' );
		$renk = [ 'bekliyor' => '#d63638', 'onaylandi' => '#00a32a', 'iptal' => '#8c8f94' ];
		$etiket = [ 'bekliyor' => '● Bekliyor', 'onaylandi' => '✓ Onaylandı', 'iptal' => '✕ İptal' ];
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Randevular</h1>
			<a href="<?php echo esc_url( $csv ); ?>" class="page-title-action">Excel'e Aktar (CSV)</a>
			<hr class="wp-header-end">

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=acente-randevu' ) ); ?>" <?php if ( ! $filtre ) echo 'class="current"'; ?>>Tümü</a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=acente-randevu&durum=bekliyor' ) ); ?>" <?php if ( 'bekliyor' === $filtre ) echo 'class="current"'; ?>>Bekliyor</a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=acente-randevu&durum=onaylandi' ) ); ?>" <?php if ( 'onaylandi' === $filtre ) echo 'class="current"'; ?>>Onaylandı</a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=acente-randevu&durum=iptal' ) ); ?>" <?php if ( 'iptal' === $filtre ) echo 'class="current"'; ?>>İptal</a> |</li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=acente-randevu&kapsam=' . ( 'tumu' === $kapsam ? 'gelecek' : 'tumu' ) ) ); ?>"><?php echo 'tumu' === $kapsam ? 'Sadece gelecek randevular' : 'Geçmişi de göster'; ?></a></li>
			</ul>

			<table class="wp-list-table widefat fixed striped" style="margin-top:12px">
				<thead><tr>
					<th style="width:150px">Tarih / Saat</th><th>Ad Soyad</th><th>Telefon</th>
					<th>Konu</th><th>Görüşme</th><th>Durum</th><th style="width:230px">İşlem</th>
				</tr></thead>
				<tbody>
				<?php if ( ! $kayitlar ) : ?>
					<tr><td colspan="7">Kayıtlı randevu yok.</td></tr>
				<?php else :
					$oncekiGun = '';
					foreach ( $kayitlar as $k ) :
						if ( $k->randevu_gun !== $oncekiGun ) {
							$oncekiGun = $k->randevu_gun;
							echo '<tr><td colspan="7" style="background:#f0f0f1;font-weight:700">'
							   . esc_html( mysql2date( 'j F Y, l', $k->randevu_gun ) ) . '</td></tr>';
						}
						$onay = wp_nonce_url( admin_url( 'admin-post.php?action=acente_rnd_durum&id=' . (int) $k->id . '&d=onaylandi' ), 'acente_rnd_durum_' . $k->id );
						$iptal= wp_nonce_url( admin_url( 'admin-post.php?action=acente_rnd_durum&id=' . (int) $k->id . '&d=iptal' ), 'acente_rnd_durum_' . $k->id );
				?>
					<tr>
						<td><strong><?php echo esc_html( substr( $k->randevu_saat, 0, 5 ) ); ?></strong>
							<span style="color:#646970"><?php echo esc_html( mysql2date( 'd.m.Y', $k->randevu_gun ) ); ?></span></td>
						<td><strong><?php echo esc_html( $k->ad_soyad ); ?></strong></td>
						<td><a href="tel:+9<?php echo esc_attr( $k->telefon ); ?>"><?php echo esc_html( $k->telefon ); ?></a></td>
						<td><?php echo esc_html( $k->konu ); ?></td>
						<td><?php echo esc_html( $k->gorusme_sekli ); ?></td>
						<td><span style="color:<?php echo esc_attr( $renk[ $k->durum ] ); ?>;font-weight:600"><?php echo esc_html( $etiket[ $k->durum ] ); ?></span></td>
						<td>
							<?php if ( 'onaylandi' !== $k->durum ) : ?>
								<a class="button button-small button-primary" href="<?php echo esc_url( $onay ); ?>">Onayla</a>
							<?php endif; ?>
							<?php if ( 'iptal' !== $k->durum ) : ?>
								<a class="button button-small" style="color:#d63638" href="<?php echo esc_url( $iptal ); ?>"
								   onclick="return confirm('Bu randevu iptal edilsin mi? Saat yeniden müsait olur.');">İptal et</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<p style="color:#646970;margin-top:14px">
				İptal edilen randevunun saati sitede yeniden müsait görünür. Kişisel veri içerir:
				KVKK saklama süresi dolan kayıtları silmeyi unutmayın.
			</p>
		</div>
		<?php
	}

	public function durum_degistir() {
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'acente_rnd_durum_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetki yok.' );
		$d = in_array( $_GET['d'] ?? '', [ 'bekliyor','onaylandi','iptal' ], true ) ? $_GET['d'] : 'bekliyor';
		global $wpdb;
		$wpdb->update( $wpdb->prefix . self::TABLO, [ 'durum' => $d ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=acente-randevu' ) );
		exit;
	}

	public function csv_indir() {
		check_admin_referer( 'acente_rnd_csv' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetki yok.' );
		global $wpdb;
		$kayitlar = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLO . ' ORDER BY randevu_gun DESC, randevu_saat DESC'
		);
		$etiket = [ 'bekliyor' => 'Bekliyor', 'onaylandi' => 'Onaylandı', 'iptal' => 'İptal' ];

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=randevular-' . gmdate( 'Y-m-d' ) . '.csv' );
		$c = fopen( 'php://output', 'w' );
		fwrite( $c, "\xEF\xBB\xBF" );
		fputcsv( $c, [ 'No','Tarih','Saat','Ad Soyad','Telefon','Konu','Görüşme Şekli','Durum','Oluşturma' ], ';' );
		foreach ( $kayitlar as $k ) {
			fputcsv( $c, [
				$k->id,
				mysql2date( 'd.m.Y', $k->randevu_gun ),
				substr( $k->randevu_saat, 0, 5 ),
				$k->ad_soyad, $k->telefon, $k->konu, $k->gorusme_sekli,
				$etiket[ $k->durum ] ?? $k->durum,
				mysql2date( 'd.m.Y H:i', $k->olusturma ),
			], ';' );
		}
		fclose( $c );
		exit;
	}

	public function ayar_kaydet() {
		if ( empty( $_POST['acente_rnd_ayar_kaydet'] ) ) return;
		check_admin_referer( 'acente_rnd_ayar' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetki yok.' );

		$eski = $this->ayar();
		$yeni = [
			'baslangic'     => preg_match( '/^\d{2}:\d{2}$/', $_POST['baslangic'] ?? '' ) ? $_POST['baslangic'] : $eski['baslangic'],
			'bitis'         => preg_match( '/^\d{2}:\d{2}$/', $_POST['bitis'] ?? '' ) ? $_POST['bitis'] : $eski['bitis'],
			'adim'          => max( 15, min( 120, (int) ( $_POST['adim'] ?? 30 ) ) ),
			'kapali_gunler' => array_map( 'intval', (array) ( $_POST['kapali_gunler'] ?? [] ) ),
			'ileri_gun'     => max( 7, min( 365, (int) ( $_POST['ileri_gun'] ?? 90 ) ) ),
			'en_erken_dk'   => max( 0, min( 1440, (int) ( $_POST['en_erken_dk'] ?? 60 ) ) ),
			'bildirim'      => empty( $_POST['bildirim'] ) ? 0 : 1,
		];
		update_option( self::OPT, $yeni );
		add_settings_error( 'acente_rnd', 'kaydedildi', 'Ayarlar kaydedildi.', 'updated' );
	}

	public function ayar_ekrani() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$a = $this->ayar();
		$gunAd = [ 'Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi' ];
		settings_errors( 'acente_rnd' );
		?>
		<div class="wrap">
			<h1>Randevu Ayarları</h1>
			<form method="post">
				<?php wp_nonce_field( 'acente_rnd_ayar' ); ?>
				<input type="hidden" name="acente_rnd_ayar_kaydet" value="1">
				<table class="form-table" role="presentation">
					<tr><th scope="row">İlk randevu saati</th>
						<td><input type="time" name="baslangic" value="<?php echo esc_attr( $a['baslangic'] ); ?>"></td></tr>
					<tr><th scope="row">Son randevu saati</th>
						<td><input type="time" name="bitis" value="<?php echo esc_attr( $a['bitis'] ); ?>"></td></tr>
					<tr><th scope="row">Randevu aralığı</th>
						<td><input type="number" name="adim" min="15" max="120" step="15" value="<?php echo (int) $a['adim']; ?>"> dakika</td></tr>
					<tr><th scope="row">Kapalı günler</th>
						<td><?php foreach ( $gunAd as $i => $ad ) : ?>
							<label style="margin-right:14px;display:inline-block">
								<input type="checkbox" name="kapali_gunler[]" value="<?php echo $i; ?>"
									<?php checked( in_array( $i, (array) $a['kapali_gunler'], true ) ); ?>>
								<?php echo esc_html( $ad ); ?>
							</label>
						<?php endforeach; ?>
						<p class="description">İşaretli günlerde takvimde randevu verilemez.</p></td></tr>
					<tr><th scope="row">Kaç gün ileriye randevu</th>
						<td><input type="number" name="ileri_gun" min="7" max="365" value="<?php echo (int) $a['ileri_gun']; ?>"> gün</td></tr>
					<tr><th scope="row">En erken randevu</th>
						<td><input type="number" name="en_erken_dk" min="0" max="1440" step="15" value="<?php echo (int) $a['en_erken_dk']; ?>"> dakika sonrası
						<p class="description">Şu andan itibaren bu süre içindeki saatler seçilemez.</p></td></tr>
					<tr><th scope="row">E-posta bildirimi</th>
						<td><label><input type="checkbox" name="bildirim" value="1" <?php checked( ! empty( $a['bildirim'] ) ); ?>>
							Yeni randevuda site yöneticisine e-posta gönder</label></td></tr>
				</table>
				<?php submit_button( 'Ayarları Kaydet' ); ?>
			</form>
		</div>
		<?php
	}
}

new Acente_Randevu();

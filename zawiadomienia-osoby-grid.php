<?php
/**
 * Plugin Name: Zawiadomienie Osoby Grid
 * Description: Wyświetla w siatce osoby przypisane do aktualnego zawiadomienia + linki do profili i filtrowanie po kategoriach. Shortcode: [zawiadomienie_osoby]
 * Version: 1.3
 * Author: Gryczan.eu
 * Author URI: https://gryczan.eu
 * Plugin URI: https://gryczan.eu
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shortcode: [zawiadomienie_osoby]
 * - Działa tylko na pojedynczym wpisie typu 'zawiadomienia' oraz na stronie o nazwie 'osoby'
 * - Wyświetla siatkę osób przypisanych w polu ACF 'osoby_w_zawiadomieniu'
 * - Każda karta: zdjęcie (klik), imię i nazwisko (klik), kategoria jako link do /osoby/?kategoria=slug, kara z ACF relacyjnego 'kara_domyslna'
 */
function zawiadomienie_osoby_shortcode() {
    global $post;

    // ✅ Rozpoznaj kontekst
    $is_osoby_page = ( is_page() && strtolower(get_the_title()) === 'osoby' );
    $is_zawiadomienie = is_singular('zawiadomienia');

    if ( !$is_osoby_page && !$is_zawiadomienie ) {
        return '<p>To nie jest zawiadomienie ani strona Osoby.</p>';
    }

    ob_start();

    // ✅ Strona "Osoby" → dodaj formularz filtrowania
    if ( $is_osoby_page ) {
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $kategoria = isset($_GET['kategoria']) ? sanitize_text_field($_GET['kategoria']) : '';

        ?>
        <form method="get" action="<?php echo esc_url(site_url('/osoby/')); ?>" class="osoby-filtr-form">
           <!-- <input type="text" name="s" placeholder="Szukaj osoby..." value="<?php echo esc_attr($search); ?>" /> -->

            <select name="kategoria">
                <option value="">Wszystkie grupy</option>
                <?php
                $terms = get_terms(array('taxonomy' => 'category', 'hide_empty' => true));
                foreach ($terms as $term) {
                    $selected = ($kategoria === $term->slug) ? 'selected' : '';
                    echo '<option value="'.esc_attr($term->slug).'" '.$selected.'>'.esc_html($term->name).'</option>';
                }
                ?>
            </select>

            <button type="submit">Filtruj</button>
        </form>
        <?php

// ✅ WP_Query dla wszystkich osób — sortuj A→Z po nazwisku (ACF 'osoba_nazwisko'), fallback na tytuł
$args = array(
    'post_type'      => 'osoba',
    'posts_per_page' => -1,
    // sortowanie po meta (ACF) - pole osoba_nazwisko
    'meta_key'       => 'osoba_nazwisko',
    // meta_value jako string
    'orderby'        => array(
        'meta_value' => 'ASC', // główne: nazwisko
        'title'      => 'ASC', // dorzut: jeśli brak nazwiska, sortuj po tytule (np. imię + nazwisko w tytule)
    ),
    'order'          => 'ASC',
);

        if ( !empty($search) ) {
            $args['s'] = $search;
        }

        if ( !empty($kategoria) ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'category',
                    'field'    => 'slug',
                    'terms'    => $kategoria,
                ),
            );
        }

        if ( !empty($search) ) {
            $args['meta_query'] = array(
                'relation' => 'OR',
                array(
                    'key'     => 'osoba_imie',
                    'value'   => $search,
                    'compare' => 'LIKE'
                ),
                array(
                    'key'     => 'osoba_nazwisko',
                    'value'   => $search,
                    'compare' => 'LIKE'
                ),
            );
        }

        $osoby = get_posts($args);

    } elseif ( $is_zawiadomienie ) {
        // ✅ Pobierz przypisane osoby tylko dla zawiadomienia (ACF relationship)
        // Pobierz przypisane osoby (ACF relationship) i posortuj je alfabetycznie po polu ACF 'osoba_nazwisko'
        $osoby = get_field('osoby_w_zawiadomieniu', $post->ID);

        if ( is_array($osoby) && ! empty($osoby) ) {
            // Ujednolicamy tablicę do listy ID (może być tablica obiektów lub ID)
            $osoby_ids = array();
            foreach ( $osoby as $o ) {
                $osoby_ids[] = is_object($o) ? $o->ID : (int) $o;
            }

            // Sortujemy ID po meta 'osoba_nazwisko' (A → Z), ignorując wielkość liter
            usort($osoby_ids, function($a, $b) {
                $naz_a = (string) get_post_meta($a, 'osoba_nazwisko', true);
                $naz_b = (string) get_post_meta($b, 'osoba_nazwisko', true);

                // jeśli oba puste -> fallback na tytuł
                if ($naz_a === '' && $naz_b === '') {
                    return strcasecmp(get_the_title($a), get_the_title($b));
                }

                // jeśli jedno puste -> traktujemy pusty jako "większe" (idzie później)
                if ($naz_a === '') return 1;
                if ($naz_b === '') return -1;

                $cmp = strcasecmp($naz_a, $naz_b);
                if ($cmp === 0) {
                    // jeśli nazwiska takie same -> fallback na tytuł
                    return strcasecmp(get_the_title($a), get_the_title($b));
                }
                return $cmp;
            });

            // Przypisz z powrotem posortowaną listę ID
            $osoby = $osoby_ids;
        }
    }

    // ✅ Wyświetl osoby w gridzie
    if ( empty($osoby) ) {
        echo '<p>Brak przypisanych osób.</p>';
    } else {
        echo '<div class="zawiadomienie-osoby-wrapper">';
        echo '<div class="zawiadomienie-osoby-grid">';

        foreach ( $osoby as $osoba ) {
            $osoba_id = is_object($osoba) ? $osoba->ID : (int)$osoba;
            $imie_nazwisko = get_the_title($osoba_id);
            $osoba_link = get_permalink($osoba_id);

            // Zdjęcie
            $zdjecie = get_field('osoba_zdjecie', $osoba_id);
            $zdjecie_url = '';
            if ( is_array($zdjecie) && !empty($zdjecie['url']) ) {
                $zdjecie_url = $zdjecie['url'];
            } elseif ( is_numeric($zdjecie) ) {
                $img = wp_get_attachment_image_src($zdjecie, 'medium');
                if ($img) { $zdjecie_url = $img[0]; }
            }
            if ( empty($zdjecie_url) ) {
                $zdjecie_url = 'https://via.placeholder.com/300x300?text=Brak+zdjecia';
            }

// Grupy (kategorie) z filtrowaniem widocznych
$grupy = get_the_terms($osoba_id, 'category');

// Pobierz zapisane ID widocznych kategorii z meta
$widoczne = get_post_meta($osoba_id, '_osoba_widoczne_kategorie', true);
if (!is_array($widoczne)) {
    $widoczne = [];
}

// Przefiltruj tylko dozwolone
if (!empty($grupy) && !is_wp_error($grupy) && !empty($widoczne)) {
    $grupy = array_filter($grupy, function($grupa) use ($widoczne) {
        return in_array($grupa->term_id, $widoczne);
    });
}

            // Kara (ACF relationship)
            $kara_txt = '';
            $kara_posty = get_field('kara_domyslna', $osoba_id);
            if ( !empty($kara_posty) ) {
                $kara_id = is_object($kara_posty[0]) ? $kara_posty[0]->ID : (int)$kara_posty[0];
                $kara_txt = get_the_title($kara_id);
            }

            // ✅ Karta osoby
            echo '<div class="osoba-card">';
          

            $zdjecie = get_field('osoba_zdjecie', $osoba_id);
            $zdjecie_url = '';
            $alt_text   = 'Zdjęcie';

            if (is_array($zdjecie) && !empty($zdjecie['url'])) {
                $zdjecie_url = $zdjecie['url'];
                $alt_text = 'Zdjęcie ' . esc_html($imie_nazwisko);
            } elseif (is_numeric($zdjecie)) {
                $img = wp_get_attachment_image_src($zdjecie, 'medium');
                if ($img) {
                    $zdjecie_url = $img[0];
                    $alt_text = 'Zdjęcie ' . esc_html($imie_nazwisko);
                }
            }

            // Fallback na plik z wtyczki
            if (empty($zdjecie_url)) {
                $zdjecie_url = plugin_dir_url(__FILE__) . 'default.webp';
                $alt_text = 'Zdjęcie';
            }

            // Wyświetlenie
            echo '  <a class="osoba-img-link" href="'.esc_url($osoba_link).'">';
            echo '      <div class="osoba-img" style="background-image:url('.esc_url($zdjecie_url).')" role="img" aria-label="'.esc_attr($alt_text).'"></div>';
            echo '  </a>';

            echo '  <div class="osoba-info">';
            echo '      <h3 class="osoba-nazwisko"><a href="'.esc_url($osoba_link).'">'.esc_html($imie_nazwisko).'</a></h3>';
            $funkcja = get_field('osoba_funkcja', $osoba_id);
            if ( $funkcja ) {
                echo '<div class="osoba-funkcja">'.esc_html($funkcja).'</div>';
            }

            if ( !empty($grupy) && !is_wp_error($grupy) ) {
                echo '<div class="osoba-grupy">';
                foreach ( $grupy as $grupa ) {
                    $grupa_link = esc_url( site_url('/osoby/?kategoria=' . $grupa->slug) );
                    echo '<a class="osoba-grupa" href="'.$grupa_link.'">'.esc_html($grupa->name).'</a>';
                }
                echo '</div>';
            }

            if ( $kara_txt ) {
                /* echo '  <p class="osoba-kara">Maksymalna kara: <strong>'.esc_html($kara_txt).'</strong></p>'; */
            }
            echo '  </div>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }

    return ob_get_clean();
}
add_shortcode('zawiadomienie_osoby', 'zawiadomienie_osoby_shortcode');


function osoba_info_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'type' => '', // imie, nazwisko, imie_nazwisko, grupa, zdjecie, kara, zawiadomienia
            'id'   => 0,  // opcjonalnie: ręcznie podany ID osoby
        ),
        $atts,
        'osoba_info'
    );

    // Ustal ID osoby (z atrybutu lub z bieżącego kontekstu)
    $osoba_id = intval($atts['id']) ?: get_the_ID();
    if (!$osoba_id) {
        return '';
    }

    switch ($atts['type']) {
        case 'imie':
            return esc_html(get_field('osoba_imie', $osoba_id));

        case 'nazwisko':
            return esc_html(get_field('osoba_nazwisko', $osoba_id));

        case 'imie_nazwisko':
            $imie = (string) get_field('osoba_imie', $osoba_id);
            $nazwisko = (string) get_field('osoba_nazwisko', $osoba_id);
            return esc_html(trim($imie . ' ' . $nazwisko));

        case 'funkcja':
            return esc_html(get_field('osoba_funkcja', $osoba_id));

case 'grupa':
    // Pobierz ID kategorii zaznaczonych jako widoczne
    $widoczne = get_post_meta($osoba_id, '_osoba_widoczne_kategorie', true);
    if (!is_array($widoczne)) {
        $widoczne = [];
    }

    // 1) Spróbuj ACF: 'grupy_osoby'
    $terms = get_field('grupy_osoby', $osoba_id);

    // 2) Fallback do zwykłej taksonomii 'category'
    if (empty($terms)) {
        $terms = get_the_terms($osoba_id, 'category');
    }

    if (!empty($terms) && !is_wp_error($terms)) {
        $links = [];

        foreach ($terms as $term) {
            // Obsłuż różne formaty zwrotu z ACF
            if (is_object($term)) {
                $term_id = $term->term_id;
                $slug    = $term->slug;
                $name    = $term->name;
            } else {
                $t = get_term((int)$term, 'category');
                if (!$t || is_wp_error($t)) { continue; }
                $term_id = $t->term_id;
                $slug    = $t->slug;
                $name    = $t->name;
            }

            // 🚀 Pokazuj tylko jeśli kategoria jest zaznaczona jako widoczna
            if (in_array($term_id, $widoczne)) {
                $href = esc_url(site_url('/osoby/?kategoria=' . $slug));
                $links[] = '<a class="osoba-grupa" href="'.$href.'">'.esc_html($name).'</a>';
            }
        }

        return $links ? '<div class="osoba-grupy">'.implode(' ', $links).'</div>' : '';
    }
    return '';

        case 'zdjecie':
            // ACF: osoba_zdjecie (pole obrazka), z fallbackiem do thumbnaila wpisu
            $zdjecie = get_field('osoba_zdjecie', $osoba_id);
            if (is_array($zdjecie) && !empty($zdjecie['ID'])) {
                return wp_get_attachment_image($zdjecie['ID'], 'medium', false, array('class' => 'osoba-foto'));
            } elseif (has_post_thumbnail($osoba_id)) {
                return get_the_post_thumbnail($osoba_id, 'medium', array('class' => 'osoba-foto'));
            }
            return '';

        case 'kara':
            // ACF relationship: kara_domyslna → CPT "kara" (tytuł = tekst kary)
            $kara_posty = get_field('kara_domyslna', $osoba_id);
            if (!empty($kara_posty)) {
                $kara_id = is_object($kara_posty[0]) ? $kara_posty[0]->ID : (int)$kara_posty[0];
                $kara_tytul = get_the_title($kara_id);
                return $kara_tytul ? esc_html($kara_tytul) : '';
            }
            return '';





case 'zawiadomienia':
    // Szukamy CPT 'zawiadomienia', gdzie ACF 'osoby_w_zawiadomieniu' zawiera ID tej osoby
    $q = new WP_Query(array(
        'post_type'      => 'zawiadomienia',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => array(
            array(
                'key'     => 'osoby_w_zawiadomieniu',
                'value'   => '"' . $osoba_id . '"', // ACF Relationship przechowuje serialized IDs
                'compare' => 'LIKE',
            ),
        ),
    ));

    if ($q->have_posts()) {
        $out = '<ul class="osoba-zawiadomienia">';
        while ($q->have_posts()) {
            $q->the_post();

            $tytul_custom = get_field('tytul_zawiadomienia');
            $tytul = $tytul_custom ? $tytul_custom : get_the_title();

            // 🔽 Pobierz pole ACF "max_kara_zawiadomienie" z bieżącego zawiadomienia
            $max_kara = get_field('max_kara_zawiadomienie');

            $out .= '<li>';
            $out .= '<h3 class="zawiadomienie-tytul"><a href="' . esc_url(get_permalink()) . '">' . esc_html($tytul) . '</a></h3>';

            // 🔽 Wyświetl maksymalną karę pod tytułem, jeśli istnieje
            if ( $max_kara ) {
                $out .= '<div class="wyroznione-max-kara"><strong>Maksymalne zagrożenie karą: </strong>' . esc_html($max_kara) . '</div>';
            }

            $out .= '</li>';
        }
        $out .= '</ul>';
        wp_reset_postdata();
        return $out;
    }

    wp_reset_postdata();
    return '<p class="osoba-zawiadomienia-empty">Brak zawiadomień.</p>';

default:
    return '';






    }
}
add_shortcode('osoba_info', 'osoba_info_shortcode');



// Dodanie meta boxa
function osoba_kategorie_metabox() {
    add_meta_box(
        'osoba_kategorie_widocznosc',
        'Widoczność kategorii',
        'osoba_kategorie_metabox_callback',
        'osoba', // <- Twój CPT
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'osoba_kategorie_metabox');

// Callback - wyświetlanie checkboxów
function osoba_kategorie_metabox_callback($post) {
    // Pobieramy wszystkie kategorie z taksonomii 'osoba_kategoria'
    $kategorie = get_terms([
        'taxonomy'   => 'category',
        'hide_empty' => false,
    ]);

    // Pobieramy zapisane dane (tablica ID kategorii)
    $zapisane = get_post_meta($post->ID, '_osoba_widoczne_kategorie', true);
    if (!is_array($zapisane)) {
        $zapisane = [];
    }

    // Generujemy listę checkboxów
    if (!empty($kategorie) && !is_wp_error($kategorie)) {
        echo '<p>Wybierz, które kategorie mają być widoczne dla tej osoby:</p>';
        foreach ($kategorie as $kat) {
            $checked = in_array($kat->term_id, $zapisane) ? 'checked' : '';
            echo '<p><label>
                <input type="checkbox" name="osoba_widoczne_kategorie[]" value="'.esc_attr($kat->term_id).'" '.$checked.'> 
                '.esc_html($kat->name).'
            </label></p>';
        }
    } else {
        echo '<p>Brak dostępnych kategorii.</p>';
    }
}

function osoba_kategorie_zapis($post_id) {
    if (isset($_POST['osoba_widoczne_kategorie'])) {
        $wybrane = array_map('intval', $_POST['osoba_widoczne_kategorie']);
        update_post_meta($post_id, '_osoba_widoczne_kategorie', $wybrane);
    } else {
        delete_post_meta($post_id, '_osoba_widoczne_kategorie');
    }
}
add_action('save_post_osoba', 'osoba_kategorie_zapis');



/**
 * Enqueue styles
 */
function zawiadomienie_osoby_enqueue_styles() {
    wp_enqueue_style('zawiadomienie-osoby-styles', plugin_dir_url(__FILE__) . 'style.css', array(), '1.3');
}
add_action('wp_enqueue_scripts', 'zawiadomienie_osoby_enqueue_styles');



/**
 * Admin page with instructions
 */
function zawiadomienie_osoby_add_admin_menu() {
    add_menu_page(
        'Zawiadomienie Osoby Grid',
        'Osoby Grid',
        'manage_options',
        'zawiadomienie-osoby-grid',
        'zawiadomienie_osoby_admin_page',
        'dashicons-groups',
        58
    );
}
add_action('admin_menu', 'zawiadomienie_osoby_add_admin_menu');

function zawiadomienie_osoby_admin_page() {
    if ( ! current_user_can('manage_options') ) { return; }

    $code_divi = htmlspecialchars(<<<'PHP'
<?php
$tax_query = array();

if ( isset($_GET['kategoria']) && !empty($_GET['kategoria']) ) {
    $tax_query[] = array(
        'taxonomy' => 'category',
        'field'    => 'slug',
        'terms'    => sanitize_text_field($_GET['kategoria']),
    );
}

$args = array(
    'post_type'      => 'osoba',
    'posts_per_page' => -1,
    'tax_query'      => $tax_query,
);

$query = new WP_Query($args);

if ( $query->have_posts() ) {
    echo '<div class="osoby-grid">';
    while ( $query->have_posts() ) {
        $query->the_post();
        echo '<div class="osoba-archive">';
        echo '<a href="'.get_permalink().'">'.get_the_title().'</a>';
        echo '</div>';
    }
    echo '</div>';
} else {
    echo '<p>Brak osób do wyświetlenia.</p>';
}
wp_reset_postdata();
?>
PHP);

    $code_archive = $code_divi; // ten sam kod dla archive-osoba.php

    echo '<div class="wrap">';
    echo '<h1>Zawiadomienie Osoby Grid — instrukcja</h1>';
    echo '<p><strong>Shortcode:</strong> <code>[zawiadomienie_osoby]</code> — wstaw na pojedynczym wpisie typu <code>zawiadomienia</code>.</p>';

    echo '<h2>Linki i filtrowanie</h2>';
    echo '<ul>';
    echo '<li>Imię i nazwisko oraz zdjęcie linkują do strony osoby (np. <code>/osoba/jan-kowalski/</code>).</li>';
    echo '<li>Kategorie przy osobie linkują do listy osób z filtrem: <code>/osoby/?kategoria=SLUG</code>.</li>';
    echo '</ul>';

    echo '<h2>Jak uruchomić filtr WP_Query dla strony /osoby/ w Divi</h2>';
    echo '<ol>';
    echo '<li>Przejdź do <em>Divi → Theme Builder</em> i utwórz szablon dla strony <strong>/osoby/</strong>.</li>';
    echo '<li>Wstaw moduł <strong>Code</strong> i wklej poniższy kod:</li>';
    echo '<pre><code>'.$code_divi.'</code></pre>';
    echo '</ol>';

    echo '<h2>Jak uruchomić filtr w klasycznym motywie (archive-osoba.php)</h2>';
    echo '<ol>';
    echo '<li>W pliku <code>archive-osoba.php</code> zastąp domyślną pętlę WP następującym kodem:</li>';
    echo '<pre><code>'.$code_archive.'</code></pre>';
    echo '</ol>';

    echo '<h2>Wskazówki CSS</h2>';
    echo '<p>Wtyczka ładuje <code>style.css</code>, który zapewnia siatkę: 2 kolumny na mobile, 4 na większych ekranach.</p>';
    echo '<p>Klasy do stylowania: <code>.zawiadomienie-osoby-grid</code>, <code>.osoba-card</code>, <code>.osoba-img</code>, <code>.osoba-info</code>, <code>.osoba-grupa</code>, <code>.osoba-kara</code>.</p>';
    echo '</div>';
}


/* MAKSYMALNA KARA SHORTCODE */

// Shortcode: [max_kara_zawiadomienie id="123"]
function shortcode_max_kara_zawiadomienie( $atts ) {
    // Atrybut shortcode — np. [max_kara_zawiadomienie id="123"]
    $atts = shortcode_atts( array(
        'id' => get_the_ID(), // jeśli brak id, pobiera aktualny post
    ), $atts, 'max_kara_zawiadomienie' );

    $post_id = (int) $atts['id'];

    // Pobierz wartość pola ACF
    $max_kara = get_field( 'max_kara_zawiadomienie', $post_id );

    if ( empty( $max_kara ) ) {
        return ''; // nic nie pokazuj jeśli brak danych
    }

    // Opcjonalnie — dodaj formatowanie
    return '<span class="max-kara-style">' . esc_html( $max_kara ) . '</span>';
}
add_shortcode( 'max_kara_zawiadomienie', 'shortcode_max_kara_zawiadomienie' );

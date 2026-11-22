<?php
/* Built-in flat illustrations (used when no picture has been uploaded)
   and small line icons. No external files needed. */

function icon($name, $size = 18) {
    $p = [
        'home' => 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z M9 22V12h6v10',
        'grid' => 'M3 3h7v7H3z M14 3h7v7h-7z M14 14h7v7h-7z M3 14h7v7H3z',
        'box' => 'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z M3.27 6.96L12 12.01l8.73-5.05 M12 22.08V12',
        'calendar' => 'M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z M16 2v4 M8 2v4 M3 10h18',
        'plus' => 'M12 5v14 M5 12h14',
        'check' => 'M22 11.08V12a10 10 0 1 1-5.93-9.14 M22 4L12 14.01l-3-3',
        'users' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M23 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75',
        'chart' => 'M12 20V10 M18 20V4 M6 20v-4',
        'user' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2 M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
        'logout' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M16 17l5-5-5-5 M21 12H9',
        'shield' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
        'clip' => 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2 M9 2h6v4H9z',
        'search' => 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16z M21 21l-4.35-4.35',
        'print' => 'M6 9V2h12v7 M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2 M6 14h12v8H6z',
        'menu' => 'M3 12h18 M3 6h18 M3 18h18',
        'eye' => 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
        'download' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4 M7 10l5 5 5-5 M12 15V3',
        'key' => 'M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4',
        'alert' => 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z M12 9v4 M12 17h.01',
        'bell' => 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9 M13.73 21a2 2 0 0 1-3.46 0',
        'lock' => 'M5 11h14v10H5z M8 11V7a4 4 0 0 1 8 0v4',
        'users2' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
    ];
    $d = $p[$name] ?? $p['grid'];
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

function art_svg($kind, $name = '') {
    $n = strtolower($name);
    $head = '<svg viewBox="0 0 400 260" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice" role="img" aria-label="' . e($name) . '"><rect width="400" height="260" fill="#efeadf"/>';
    $s = '';

    if ($kind === 'facility') {
        if (preg_match('/comput|laborator|\\blab\\b|cict/', $n)) {
            // Computer laboratory
            $s .= '<rect y="196" width="400" height="64" fill="#e6e0d3"/>';
            $s .= '<rect x="34" y="28" width="332" height="84" rx="6" fill="#fff"/><rect x="52" y="46" width="120" height="7" rx="3.5" fill="#d8d1c3"/><rect x="52" y="62" width="200" height="7" rx="3.5" fill="#e1e4ea"/><rect x="52" y="78" width="90" height="7" rx="3.5" fill="#e1e4ea"/><rect x="290" y="46" width="58" height="46" rx="5" fill="#e0e8ef"/>';
            foreach ([28, 146, 264] as $x) {
                $s .= '<rect x="' . $x . '" y="132" width="108" height="64" rx="5" fill="#2e3d4f"/>';
                $s .= '<rect x="' . ($x + 6) . '" y="138" width="96" height="50" rx="2" fill="#8ea4b8"/>';
                $s .= '<rect x="' . ($x + 14) . '" y="146" width="40" height="5" rx="2.5" fill="#fff" opacity=".7"/><rect x="' . ($x + 14) . '" y="157" width="64" height="5" rx="2.5" fill="#fff" opacity=".4"/>';
                $s .= '<rect x="' . ($x + 46) . '" y="196" width="16" height="12" fill="#a39c8c"/>';
                $s .= '<rect x="' . ($x + 30) . '" y="206" width="48" height="5" rx="2.5" fill="#a39c8c"/>';
            }
            $s .= '<rect x="0" y="214" width="400" height="14" fill="#d3ccbc"/>';
        } elseif (preg_match('/conference|meeting|board/', $n)) {
            // Conference room
            $s .= '<rect y="190" width="400" height="70" fill="#e6e0d3"/>';
            $s .= '<rect x="120" y="26" width="160" height="82" rx="5" fill="#2e3d4f"/><rect x="127" y="33" width="146" height="68" rx="2" fill="#f2f4f7"/><rect x="140" y="46" width="60" height="6" rx="3" fill="#cdc6b6"/><rect x="140" y="60" width="100" height="6" rx="3" fill="#e6e0d3"/><rect x="140" y="74" width="40" height="14" rx="3" fill="#8ea4b8"/>';
            $s .= '<ellipse cx="200" cy="184" rx="130" ry="38" fill="#cdc6b6"/><ellipse cx="200" cy="178" rx="130" ry="38" fill="#fff"/>';
            foreach ([[96, 140], [150, 126], [250, 126], [304, 140], [96, 212], [150, 226], [250, 226], [304, 212]] as $c) {
                $s .= '<circle cx="' . $c[0] . '" cy="' . $c[1] . '" r="13" fill="#2e3d4f"/>';
            }
        } elseif (preg_match('/audio|visual|av|seminar|auditor|hall|theat/', $n)) {
            // Audio-visual room
            $s .= '<rect y="180" width="400" height="80" fill="#e6e0d3"/>';
            $s .= '<rect x="70" y="24" width="260" height="104" rx="5" fill="#fff"/><rect x="82" y="36" width="236" height="80" rx="2" fill="#e0e8ef"/><circle cx="170" cy="76" r="20" fill="#8ea4b8"/><path d="M162 66l18 10-18 10z" fill="#fff"/><rect x="204" y="62" width="86" height="7" rx="3.5" fill="#fff" opacity=".8"/><rect x="204" y="78" width="60" height="7" rx="3.5" fill="#fff" opacity=".55"/>';
            for ($row = 0; $row < 3; $row++) {
                for ($i = 0; $i < 7; $i++) {
                    $x = 44 + $i * 46 + ($row % 2) * 10;
                    $y = 150 + $row * 34;
                    $s .= '<rect x="' . $x . '" y="' . $y . '" width="34" height="24" rx="8" fill="' . ($row == 1 ? '#2e3d4f' : '#46586b') . '"/>';
                }
            }
        } else {
            // Classroom / generic room
            $s .= '<rect y="190" width="400" height="70" fill="#e6e0d3"/>';
            $s .= '<rect x="50" y="26" width="300" height="86" rx="5" fill="#2e3d4f"/><rect x="62" y="38" width="130" height="6" rx="3" fill="#fff" opacity=".7"/><rect x="62" y="52" width="90" height="6" rx="3" fill="#fff" opacity=".4"/><rect x="62" y="98" width="276" height="5" rx="2.5" fill="#cbb98a"/>';
            foreach ([0, 1] as $row) {
                foreach ([0, 1, 2, 3] as $i) {
                    $x = 48 + $i * 80;
                    $y = 138 + $row * 52;
                    $s .= '<rect x="' . $x . '" y="' . $y . '" width="56" height="22" rx="4" fill="#fff"/><rect x="' . ($x + 8) . '" y="' . ($y + 22) . '" width="4" height="16" fill="#a39c8c"/><rect x="' . ($x + 44) . '" y="' . ($y + 22) . '" width="4" height="16" fill="#a39c8c"/>';
                }
            }
        }
    } else {
        // Equipment: product on a soft floor
        $s .= '<ellipse cx="200" cy="214" rx="120" ry="14" fill="#e1dbce"/>';
        if (preg_match('/laptop|notebook|computer|tablet/', $n)) {
            $s .= '<path d="M92 190l26-98a8 8 0 0 1 8-6h148a8 8 0 0 1 8 6l26 98z" fill="#2e3d4f"/><path d="M108 182l22-84h140l22 84z" fill="#8ea4b8"/><rect x="132" y="112" width="70" height="6" rx="3" fill="#fff" opacity=".7"/><rect x="132" y="126" width="110" height="6" rx="3" fill="#fff" opacity=".4"/>';
            $s .= '<path d="M66 190h268l-10 18a8 8 0 0 1-7 4H83a8 8 0 0 1-7-4z" fill="#c4bdad"/><rect x="170" y="192" width="60" height="5" rx="2.5" fill="#9096a4"/>';
        } elseif (preg_match('/project/', $n)) {
            $s .= '<path d="M262 120l90-40v100l-90-30z" fill="#fff6d6" opacity=".85"/>';
            $s .= '<rect x="70" y="112" width="200" height="82" rx="14" fill="#fff"/><rect x="70" y="112" width="200" height="82" rx="14" fill="none" stroke="#cdc6b6" stroke-width="2"/>';
            $s .= '<circle cx="232" cy="153" r="28" fill="#2e3d4f"/><circle cx="232" cy="153" r="18" fill="#8ea4b8"/><circle cx="226" cy="147" r="5" fill="#fff" opacity=".7"/>';
            $s .= '<rect x="92" y="132" width="70" height="6" rx="3" fill="#e6e0d3"/><rect x="92" y="148" width="50" height="6" rx="3" fill="#e6e0d3"/><circle cx="104" cy="174" r="5" fill="#16a34a"/><rect x="100" y="194" width="14" height="10" fill="#a39c8c"/><rect x="226" y="194" width="14" height="10" fill="#a39c8c"/>';
        } elseif (preg_match('/camera|cam|video/', $n)) {
            $s .= '<rect x="96" y="104" width="208" height="96" rx="14" fill="#2e3d4f"/><rect x="132" y="88" width="70" height="22" rx="6" fill="#46586b"/>';
            $s .= '<circle cx="200" cy="152" r="42" fill="#46586b"/><circle cx="200" cy="152" r="30" fill="#0f1114"/><circle cx="200" cy="152" r="16" fill="#8ea4b8" opacity=".9"/><circle cx="193" cy="145" r="5" fill="#fff" opacity=".7"/>';
            $s .= '<circle cx="272" cy="124" r="6" fill="#c0553f"/><rect x="112" y="118" width="30" height="8" rx="4" fill="#a39c8c"/>';
        } elseif (preg_match('/mic|microphone/', $n)) {
            $s .= '<rect x="150" y="204" width="100" height="8" rx="4" fill="#a39c8c"/><rect x="196" y="140" width="8" height="66" fill="#46586b"/>';
            $s .= '<rect x="170" y="48" width="60" height="100" rx="30" fill="#2e3d4f"/>';
            for ($i = 0; $i < 6; $i++) $s .= '<rect x="176" y="' . (62 + $i * 12) . '" width="48" height="3" rx="1.5" fill="#8c9490"/>';
            $s .= '<path d="M158 112a42 42 0 0 0 84 0" fill="none" stroke="#a39c8c" stroke-width="5" stroke-linecap="round"/>';
        } elseif (preg_match('/speaker|audio|sound/', $n)) {
            $s .= '<rect x="130" y="50" width="140" height="158" rx="16" fill="#2e3d4f"/><circle cx="200" cy="100" r="22" fill="#46586b"/><circle cx="200" cy="100" r="9" fill="#8c9490"/><circle cx="200" cy="162" r="34" fill="#46586b"/><circle cx="200" cy="162" r="22" fill="#0f1114"/><circle cx="200" cy="162" r="9" fill="#8c9490"/>';
        } elseif (preg_match('/cord|extension|cable|wire/', $n)) {
            $s .= '<rect x="86" y="130" width="228" height="48" rx="10" fill="#fff" stroke="#cdc6b6" stroke-width="2"/>';
            foreach ([0, 1, 2, 3] as $i) $s .= '<rect x="' . (112 + $i * 48) . '" y="144" width="26" height="20" rx="4" fill="#2e3d4f"/>';
            $s .= '<path d="M86 154c-40 0-40 40 0 40" fill="none" stroke="#46586b" stroke-width="5"/>';
        } else {
            $s .= '<path d="M120 100l80-34 80 34v82l-80 34-80-34z" fill="#fff" stroke="#cdc6b6" stroke-width="2"/><path d="M120 100l80 34 80-34 M200 134v82" fill="none" stroke="#cdc6b6" stroke-width="2"/><rect x="158" y="120" width="40" height="10" rx="3" fill="#8ea4b8" transform="rotate(20 178 125)"/>';
        }
    }
    return $head . $s . '</svg>';
}

<?php
// The help center became the User's Manual; keep old links working.
$a = (string)input('a', '');
$q = (string)input('q', '');
redirect(url('manual', array_filter(array('s' => $a, 'q' => $q), 'strlen')));

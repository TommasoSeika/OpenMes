<?php

// Codespaces only: mounted over config/octane.php in the backend container by
// .devcontainer/docker-compose.codespaces.yml.
//
// OpenMES ships no config/octane.php, so Octane's package default applies and
// RoadRunner stops the worker of any request that runs longer than 30 seconds
// (http.pool.supervisor.exec_ttl). Installing an example company from the
// browser (the onboarding screen, or Settings → Data) runs the demo seeders
// inside one request, and on a 2-core codespace that takes longer. The worker
// was stopped mid-install: the data was left half loaded and the install was
// never recorded as finished.
//
// Only this key is set. Octane merges its own defaults underneath, so every
// other option keeps its stock value.

return [
    'max_execution_time' => (int) env('OCTANE_MAX_EXECUTION_TIME', 300),
];

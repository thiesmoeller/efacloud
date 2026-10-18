<?php
/**
 *
 *       efaCloud
 *       --------
 *       https://www.efacloud.org
 *
 * Copyright  2018-2024  Martin Glade
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


// ===== initialize toolbox and socket and start session.
$user_requested_file = __FILE__;
include_once "../classes/init.php";
include_once "../classes/splash_hub.php";

// Branded splash: three equal frontends (Verwaltung / Portal / Statistik).
Splash_hub::begin_page($menu);
Splash_hub::open_hub();
echo "<p class='login-lead'>" . i("lN8wPq|Administration, efaWeb and ...") . "</p>\n";
echo Splash_hub::render_dispatcher();
Splash_hub::close_hub();

end_script();

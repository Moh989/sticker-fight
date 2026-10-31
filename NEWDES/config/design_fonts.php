<?php

return json_decode(file_get_contents(resource_path('design-fonts.json')), true, 512, JSON_THROW_ON_ERROR);

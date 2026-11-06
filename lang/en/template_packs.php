<?php

// Template packs per kind of business (ADR-0019).
return [
    'component_unknown' => 'Component :id is not in this pack.',
    'exclude_not_picked' => 'Component :id is not picked, so items in it cannot be excluded.',
    'item_unknown' => 'Item :key is not in component :id.',
    'item_conflict' => 'Item :key in component :id conflicts with the data of the company: :reason Leave that item out.',
    'component_not_in_profile' => 'Component :id is not in the :profile profile of this pack.',
    'profile_unknown' => 'Pack has no profile :profile.',
];

<?php

declare(strict_types=1);

namespace Solo\BaseRepository\Relation;

enum RelationKind: string
{
    case BelongsTo = 'belongsTo';
    case HasOne = 'hasOne';
    case HasMany = 'hasMany';
    case BelongsToMany = 'belongsToMany';
}

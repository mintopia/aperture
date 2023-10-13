@if ($params['order'] == $field)
    <a href="{{ request()->fullUrlWithQuery(['order' => $field, 'direction' => ($params['direction'] == 'asc' ? 'desc' : 'asc')]) }}">{{ $title }}</a>
    <small></span><i class="icon ti ti-{{ $params['direction'] == 'asc' ? 'sort-ascending-2' : 'sort-descending-2' }}"></i></small>
@else
    <a href="{{ request()->fullUrlWithQuery(['order' => $field, 'direction' => (isset($direction) ? $direction : 'asc')]) }}">{{ $title }}</a>
@endif

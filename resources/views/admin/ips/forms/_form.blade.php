{{ csrf_field() }}
<div class="mb-3">
    <label class="form-label" for="address">IP Address</label>
    <input class="form-control" type="text" name="address" id="address" placeholder="127.0.0.1" value="{{ old('address') }}"/>
</div>
<div class="mb-3">
    <label class="form-label" for="comment">Comment</label>
    <input class="form-control" type="text" name="comment" id="comment" placeholder="Comment" value="{{ old('comment') }}"/>
</div>

<div class="mb-3">
    <label class="form-check">
        <input class="form-check-input" type="checkbox" name="allow" value="1">
        <span class="form-check-label">Allow Internet</span>
    </label>
</div>

<div class="mb-3">
    <label class="form-check">
        <input class="form-check-input" type="checkbox" name="limit" value="1">
        <span class="form-check-label">Rate Limit</span>
    </label>
</div>

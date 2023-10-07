<div class="form-group">
    <label class="form-label" for="address">IP Address</label>
    <input class="form-control @error('address') is-invalid @enderror" type="text" name="address" placeholder="IP Address" value="{{ $filters->address }}" />
    @error('address')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="form-group mt-3">
    <label class="form-label" for="nickname">Nickname</label>
    <input class="form-control @error('nickname') is-invalid @enderror" type="text" name="nickname" placeholder="Nickname" value="{{ $filters->nickname }}" />
    @error('nickname')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

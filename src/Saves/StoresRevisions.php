<?php

namespace GamePlatform\Saves;

/**
 * For a game's save model: the full save as gzipped JSON, kept as numbered revisions in
 * object storage (see SaveFiles), with the row pointing at the latest. Where storage
 * isn't set up, the save stays in the row's `state` column instead.
 *
 * The model needs `revision` (int, 0 before the first save), `path` (nullable string)
 * and `state` (nullable binary) columns, and says which game's folder it saves into.
 */
trait StoresRevisions
{
    /** The game whose folder the save files go in. */
    abstract protected function saveFolderId(): int;

    /**
     * Save as the next revision: a new file, with older ones beyond the last few removed.
     * save() the model afterwards to point at the new file.
     *
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        return $this->setRaw((string) gzencode((string) json_encode($data, JSON_THROW_ON_ERROR)));
    }

    /** As setData(), for a save that's already gzipped JSON. */
    public function setRaw(string $gzipped): static
    {
        $revision = (int) ($this->getAttribute('revision') ?? 0) + 1;
        $this->setAttribute('revision', $revision);

        if (! SaveFiles::enabled()) {
            $this->forceFill(['path' => null, 'state' => $gzipped]);

            return $this;
        }

        $id = $this->saveFolderId();
        $path = SaveFiles::savePath($id, $revision);
        SaveFiles::put($path, $gzipped, 'application/gzip');
        $this->forceFill(['path' => $path, 'state' => null]);

        $old = $revision - SaveFiles::KEEP_SAVES;
        if ($old > 0) {
            SaveFiles::disk()->delete(SaveFiles::savePath($id, $old));
        }

        return $this;
    }

    /** The save as stored: gzipped JSON. */
    public function raw(): string
    {
        $path = $this->getAttribute('path');

        return is_string($path) ? (string) SaveFiles::disk()->get($path) : (string) $this->getAttribute('state');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $json = gzdecode($this->raw());

        return $json === false ? [] : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** A link the browser can fetch the save from directly, if storage can make one. */
    public function link(): ?string
    {
        $path = $this->getAttribute('path');

        return is_string($path) ? SaveFiles::link($path) : null;
    }
}

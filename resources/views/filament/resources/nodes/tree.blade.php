<x-filament::section>
    <div
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('tree-view', 'solutionforest/filament-nestable-tree') }}"
        x-data="treeView({
            data: $wire.entangle('treeNodes'),
            asyncChildren: true,
            allowDragDrop: false,
            allowCrossCategory: false,
            maxVisibleDepth: Number.MAX_SAFE_INTEGER,
        })"
        x-on:node-created.window="
            const parent = _findById(treeData, $event.detail.parentId);
            if (parent) {
                const previous = new Map((parent.children ?? []).map(child => [child.id, child]));
                parent.children = $event.detail.children.map(child => {
                    const existing = previous.get(child.id);
                    return existing ? { ...child, children: existing.children, _childrenLoaded: existing._childrenLoaded, expanded: existing.expanded } : child;
                });
                parent._childrenLoaded = true;
                parent.expanded = true;
            }
        "
        class="filament-nestable-tree"
    >
        <div class="fi-tree-toolbar">
            <x-filament::button color="gray" icon="heroicon-o-chevron-up" x-on:click="collapseAll()">
                Свернуть всё
            </x-filament::button>
        </div>

        <div role="tree" aria-label="Организации и органы управления" class="fi-tree-node-list">
            <template x-for="node in flattenedVisibleNodes" :key="node.id">
                <div
                    role="treeitem"
                    tabindex="0"
                    class="fi-tree-node-row"
                    :style="'width: 100%; text-align: left; padding-left: ' + ((node._depth * 20) + 12) + 'px'"
                    :aria-level="node._depth + 1"
                    :aria-expanded="node._hasChildren ? String(node._isExpanded) : null"
                    :aria-busy="loadingNodeId === String(node.id)"
                    :aria-disabled="loadingNodeId !== null"
                    x-on:click="if (loadingNodeId === null && node._hasChildren) toggleNode(node.id)"
                    x-on:keydown.enter.self.prevent="if (loadingNodeId === null && node._hasChildren) toggleNode(node.id)"
                    x-on:keydown.space.self.prevent="if (loadingNodeId === null && node._hasChildren) toggleNode(node.id)"
                    x-on:keydown.right.prevent="if (node._hasChildren && !node._isExpanded) toggleNode(node.id)"
                    x-on:keydown.left.prevent="if (node._isExpanded) toggleNode(node.id)"
                >
                    <span class="fi-tree-node-toggle" :class="{ 'fi-tree-node-toggle--hidden': !node._hasChildren }">
                        <x-filament::icon
                            icon="heroicon-m-chevron-right"
                            class="fi-tree-toggle-icon"
                            x-bind:class="{ 'fi-tree-toggle-icon--expanded': node._isExpanded }"
                        />
                    </span>

                    @foreach (['authority' => 'heroicon-o-building-library', 'organization' => 'heroicon-o-building-office-2', 'branch' => 'heroicon-o-building-office'] as $type => $icon)
                        <template x-if="node.type === @js($type)">
                            <x-filament::icon
                                :icon="$icon"
                                class="fi-tree-toggle-icon"
                            />
                        </template>
                    @endforeach

                    <span class="fi-tree-node-label" x-text="node.name"></span>
                    <span x-show="loadingNodeId === String(node.id)" x-cloak role="status">Загрузка…</span>
                    <template x-if="node.can_create_child">
                        <x-filament::icon-button
                            icon="heroicon-o-plus"
                            label="Добавить дочерний узел"
                            color="gray"
                            x-on:click.stop="$wire.mountAction('createChild', { parentId: node.id })"
                        />
                    </template>
                </div>
            </template>
        </div>

        <p class="fi-tree-empty-state" x-show="treeData.length === 0" x-cloak>
            Организации не найдены.
        </p>
    </div>
</x-filament::section>

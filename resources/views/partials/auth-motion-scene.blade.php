{{-- Decorative background for the sign-in screens: a soft green mesh and a few leaves. Styled by resources/css/auth-motion.css. --}}
<div class="am-scene" aria-hidden="true">
    <svg class="am-defs" width="0" height="0" focusable="false">
        <symbol id="am-leaf-shape" viewBox="0 0 64 64">
            <path fill-rule="evenodd" d="M32 3C15 15 7 32 13 47c4 10 13 14 19 14s15-4 19-14C57 32 49 15 32 3Zm0 9v44c-1 0-2-1-2-2V14c0-1 1-2 2-2Z"/>
        </symbol>
    </svg>
    <div class="am-layer am-layer-mesh">
        <span class="am-blob am-blob-a"></span>
        <span class="am-blob am-blob-b"></span>
        <span class="am-blob am-blob-c"></span>
    </div>
    <div class="am-layer am-layer-leaves">
        @foreach ([1, 2, 3, 4, 5] as $leaf)
            <svg class="am-leaf am-leaf-{{ $leaf }}" focusable="false"><use href="#am-leaf-shape"/></svg>
        @endforeach
    </div>
</div>
